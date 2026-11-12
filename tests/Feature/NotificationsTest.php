<?php

use App\Models\Customer;
use App\Notifications\FailingChannel;
use App\Notifications\FailingChannelNotification;
use App\Notifications\OrderShippedNotification;
use App\Notifications\SmsAndDatabaseNotification;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'mail.mailers.array' => ['transport' => 'array'],
        'mail.from' => ['address' => 'shop@example.com', 'name' => 'Shop'],
    ]);

    $noop = fn () => new class
    {
        public function send(object $notifiable, mixed $notification): void {}
    };
    app(ChannelManager::class)->extend('vonage', $noop);
    app(ChannelManager::class)->extend('database', $noop);

    $this->transport = $this->fakeTransport();
});

function notificationRecords(object $test): array
{
    return [...$test->transport->bufferedOfType('wt-notification'), ...$test->transport->ofType('wt-notification')];
}

function customer(int $id = 42, array $attributes = []): Customer
{
    $customer = new Customer(['name' => 'Jane Doe', 'email' => 'jane@example.com', ...$attributes]);
    $customer->id = $id;
    $customer->exists = true;

    return $customer;
}

it('records the recipient of a sent notification with its real route by default', function () {
    customer()->notify(new OrderShippedNotification);

    $records = notificationRecords($this);

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-notification');
    expect($records[0])->toMatchArray([
        'notification' => OrderShippedNotification::class,
        'channel' => 'mail',
        'status' => 'sent',
        'notifiable_type' => 'Customer',
        'notifiable_class' => Customer::class,
        'notifiable_key' => '42',
        'label' => 'Jane Doe',
        'route' => 'jane@example.com',
        'error' => null,
    ])
        ->and($records[0]['notification_id'])->toBeString()
        ->and($records[0]['duration'])->toBeInt()
        ->and($records[0]['execution_id'])->toBe($this->transport->execution['execution_id'])
        // Buffered with the execution like the other in-execution records.
        ->and($this->transport->bufferedOfType('wt-notification'))->toHaveCount(1);
});

it('leaves routes out when recipient_routes is off', function () {
    config(['watchtower-agent.notifications.recipient_routes' => false]);

    customer()->notify(new OrderShippedNotification);
    Notification::route('mail', 'ops@example.org')->notify(new OrderShippedNotification);

    [$model, $anonymous] = notificationRecords($this);

    expect($model['route'])->toBeNull()
        ->and($model['label'])->toBe('Jane Doe')
        ->and($anonymous)->toMatchArray(['notifiable_type' => 'AnonymousNotifiable', 'notifiable_key' => null, 'label' => 'On-demand', 'route' => null])
        ->and(json_encode(notificationRecords($this)))->not->toContain('@example.');
});

it('records one row per recipient and channel of a broadcast', function () {
    Notification::send([customer(1, ['name' => 'Ann']), customer(2, ['name' => 'Bob'])], new SmsAndDatabaseNotification);

    $records = notificationRecords($this);

    expect($records)->toHaveCount(4)
        ->and(array_map(fn (array $r) => $r['label'].'|'.$r['channel'].'|'.($r['route'] ?? '-'), $records))->toBe([
            'Ann|database|-', 'Ann|vonage|+15550100', 'Bob|database|-', 'Bob|vonage|+15550100',
        ]);
});

it('uses the route of an on-demand notification as key and label', function () {
    Notification::route('mail', ['ops@example.org' => 'Ops'])->notify(new OrderShippedNotification);

    expect(notificationRecords($this)[0])->toMatchArray([
        'notifiable_class' => AnonymousNotifiable::class,
        'notifiable_key' => 'ops@example.org',
        'label' => 'ops@example.org',
        'route' => 'ops@example.org',
    ]);
});

it('falls back to "Type #key" without the label attribute and never reads sensitive ones', function () {
    config(['watchtower-agent.notifications.label_attribute' => 'nickname']);
    customer()->notify(new OrderShippedNotification);

    config(['watchtower-agent.notifications.label_attribute' => 'password']);
    customer(7, ['password' => 'hunter2'])->notify(new OrderShippedNotification);

    [$first, $second] = notificationRecords($this);

    expect($first['label'])->toBe('Customer #42')
        ->and($second['label'])->toBe('Customer #7')
        ->and(json_encode($second))->not->toContain('hunter2');
});

it('sends failures immediately with a redacted, capped reason', function () {
    expect(fn () => customer()->notify(new FailingChannelNotification))->toThrow(RuntimeException::class);

    $sent = $this->transport->ofType('wt-notification');

    expect($sent)->toHaveCount(1)
        ->and($this->transport->bufferedOfType('wt-notification'))->toBe([]);
    Contract::assertRecord($sent[0], 'wt-notification');
    expect($sent[0])->toMatchArray([
        'status' => 'failed',
        'channel' => FailingChannel::class,
        'label' => 'Jane Doe',
    ])
        ->and($sent[0]['error'])->toStartWith('RuntimeException: SMTP said no')
        ->and($sent[0]['error'])->not->toContain('sk_live_123')
        ->and($sent[0]['duration'])->toBeInt();
});

it('caps records per execution', function () {
    config(['watchtower-agent.notifications.max_per_execution' => 2]);

    Notification::send([customer(1), customer(2), customer(3)], new OrderShippedNotification);

    expect(notificationRecords($this))->toHaveCount(2);
});

it('records nothing when the feature is off or Nightwatch is not capturing', function () {
    config(['watchtower-agent.features.notifications' => false]);
    customer()->notify(new OrderShippedNotification);

    config(['watchtower-agent.features.notifications' => true]);
    $this->transport->execution = null;
    customer()->notify(new OrderShippedNotification);

    expect(notificationRecords($this))->toBe([]);
});
