<?php

use App\Mail\FailingTransport;
use App\Mail\OrderShippedMail;
use App\Notifications\OrderShippedNotification;
use App\Notifications\QueuedOrderShippedNotification;
use Cyberwizard\WatchtowerAgent\Mail\InstrumentedTransport;
use Cyberwizard\WatchtowerAgent\Mail\MailRecorder;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Tests\Support\QueueTables;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    View::addLocation(__DIR__.'/../Fixtures/views');
    config([
        'mail.default' => 'array',
        'mail.mailers.array' => ['transport' => 'array'],
        'mail.mailers.failing' => ['transport' => 'failing'],
        'mail.from' => ['address' => 'shop@example.com', 'name' => 'Shop'],
    ]);

    $this->transport = $this->fakeTransport();
});

function mails(object $test, ?string $stage = null): array
{
    return array_values(array_filter(
        $test->transport->bufferedOfType('wt-mail'),
        fn (array $record) => $stage === null || $record['stage'] === $stage,
    ));
}

function sendOrderMail(): void
{
    Mail::to('jane@example.com')->cc('bob@example.org')->bcc('audit@example.net')->send(new OrderShippedMail);
}

it('records sending and sent with the transport Message-ID, matching the contract', function () {
    sendOrderMail();

    [$sending, $sent] = mails($this);

    Contract::assertRecord($sending, 'wt-mail');
    Contract::assertRecord($sent, 'wt-mail');

    $message = app('mailer')->getSymfonyTransport()->inner()->messages()->first();
    $header = $message->getOriginalMessage()->getHeaders()->get(MailRecorder::HEADER)->getBodyAsString();

    expect($sending['stage'])->toBe('sending')
        ->and($sent)->toMatchArray([
            'mail_id' => $header,
            'stage' => 'sent',
            'mailable' => OrderShippedMail::class,
            'notification' => null,
            'mailer' => 'array',
            'transport' => 'array',
            'from' => 's***@example.com',
            'to' => ['j***@example.com'],
            'cc' => ['b***@example.org'],
            'bcc' => [],
            'subject' => 'Your order shipped',
            'message_id' => $message->getMessageId(),
            'preview' => null,
            'queued' => false,
            'job_id' => null,
            'error' => null,
        ])
        ->and($sending['mail_id'])->toBe($header)
        ->and($sent['duration'])->toBeInt();
});

it('does not duplicate Nightwatch\'s own mail record', function () {
    sendOrderMail();

    $types = array_count_values(array_column($this->ingest->written, 't'));

    expect($types['mail'] ?? 0)->toBe(1)
        ->and($this->ingest->written)->each(fn ($record) => $record->not->toHaveKey('mail_id'));
});

it('supports full and hidden recipients and opt-in bcc', function () {
    config(['watchtower-agent.mail.recipients' => 'full', 'watchtower-agent.mail.include_bcc' => true]);
    sendOrderMail();

    expect(mails($this, 'sent')[0])->toMatchArray([
        'to' => ['jane@example.com'],
        'bcc' => ['audit@example.net'],
        'from' => 'shop@example.com',
    ]);

    config(['watchtower-agent.mail.recipients' => 'none']);
    sendOrderMail();

    expect(mails($this, 'sent')[1])->toMatchArray(['to' => [], 'cc' => [], 'bcc' => [], 'from' => null]);
});

it('sends a redacted text preview only when enabled', function () {
    config(['watchtower-agent.mail.preview' => true]);

    sendOrderMail();

    expect(mails($this, 'sent')[0]['preview'])->toBe("Hello Jane, your reset token=[redacted] is inside.\n");
});

it('records transport failures and rethrows them', function () {
    Mail::extend('failing', fn () => new FailingTransport);

    expect(fn () => Mail::mailer('failing')->to('jane@example.com')->send(new OrderShippedMail))
        ->toThrow(TransportException::class);

    $failed = mails($this, 'failed');

    expect($failed)->toHaveCount(1);
    Contract::assertRecord($failed[0], 'wt-mail');
    expect($failed[0])->toMatchArray([
        'mailer' => 'failing',
        'transport' => 'failing',
        'error' => 'Symfony\Component\Mailer\Exception\TransportException: Connection to smtp.example.com:587 refused',
        'mail_id' => mails($this, 'sending')[0]['mail_id'],
    ])->and(Mail::mailer('failing')->getSymfonyTransport())->toBeInstanceOf(InstrumentedTransport::class);
});

it('follows a queued mailable from queued to sent under one mail id', function () {
    QueueTables::create();
    config(['queue.default' => 'database']);

    Mail::to('jane@example.com')->queue(new OrderShippedMail);

    $queued = mails($this, 'queued');

    expect($queued)->toHaveCount(1);
    Contract::assertRecord($queued[0], 'wt-mail');
    expect($queued[0])->toMatchArray([
        'mailable' => OrderShippedMail::class,
        'to' => ['j***@example.com'],
        'queued' => true,
    ])->and($queued[0]['job_id'])->toBeString();

    Context::flush();
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);

    $sent = mails($this, 'sent')[0];

    expect($sent)->toMatchArray([
        'mail_id' => $queued[0]['mail_id'],
        'queued' => true,
        'job_id' => $queued[0]['job_id'],
    ]);
});

it('records mail notifications, sync and queued', function () {
    QueueTables::create();
    config(['queue.default' => 'database']);

    Notification::route('mail', 'jane@example.com')->notify(new OrderShippedNotification);

    expect(mails($this, 'sent')[0])->toMatchArray([
        'notification' => OrderShippedNotification::class,
        'mailable' => null,
        'subject' => 'Shipped!',
        'queued' => false,
    ]);

    Notification::route('mail', 'jane@example.com')->notify(new QueuedOrderShippedNotification);

    expect(mails($this, 'queued')[0])->toMatchArray([
        'notification' => QueuedOrderShippedNotification::class,
        'to' => [],
    ]);
});

it('does nothing when the feature is off', function () {
    config(['watchtower-agent.features.mail' => false]);

    sendOrderMail();

    expect(mails($this))->toBe([]);
});
