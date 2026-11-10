<?php

namespace Cyberwizard\WatchtowerAgent\Mail;

use Cyberwizard\WatchtowerAgent\Queue\CurrentJob;
use Cyberwizard\WatchtowerAgent\Queue\QueuePayload;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Contracts\Container\Container;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * wt-mail: enrichment for Nightwatch's mail record. Each message gets an id in the
 * X-Watchtower-Mail-Id header; stages are queued (SendQueuedMailable or a mail notification
 * pushed), sending (MessageSending), sent (MessageSent, with the transport Message-ID provider
 * webhooks match on) and failed (the transport threw). "Sent" means the transport accepted the
 * message, never that it was delivered.
 */
class MailRecorder
{
    public const HEADER = 'X-Watchtower-Mail-Id';

    /** @var array<string, int> */
    private array $started = [];

    /**
     * Mailer data of messages being sent, so a transport failure knows its mailer.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $data = [];

    /** Mail id carried in the payload of the queued job being processed. */
    private ?string $jobMailId = null;

    private ?CurrentJob $job = null;

    public function __construct(private Container $container) {}

    public function queued(JobQueued $event): void
    {
        $job = $event->job;

        if (Guard::active() || ! WatchtowerAgent::feature('mail')) {
            return;
        }

        if (! $job instanceof SendQueuedMailable
            && ! ($job instanceof SendQueuedNotifications && in_array('mail', (array) $job->channels, true))) {
            return;
        }

        Guard::safely(function () use ($event, $job): void {
            $payload = QueuePayload::fromQueuedEvent($event);
            $mailable = $job instanceof SendQueuedMailable ? $job->mailable : null;
            $mailer = (string) ($mailable->mailer ?? null ?: config('mail.default', ''));

            $this->record([
                'mail_id' => $payload['watchtower']['mail_id'] ?? (string) Str::uuid(),
                'stage' => 'queued',
                'mailable' => $mailable !== null ? $mailable::class : null,
                'notification' => $job instanceof SendQueuedNotifications ? $job->notification::class : null,
                'mailer' => $mailer,
                'transport' => $this->transport($mailer),
                'from' => $mailable !== null ? $this->first($this->mailableAddresses($mailable->from ?? [])) : null,
                'to' => $mailable !== null ? $this->addresses($this->mailableAddresses($mailable->to ?? [])) : [],
                'cc' => $mailable !== null ? $this->addresses($this->mailableAddresses($mailable->cc ?? [])) : [],
                'bcc' => $mailable !== null ? $this->bcc($this->mailableAddresses($mailable->bcc ?? [])) : [],
                'subject' => $mailable !== null ? $this->mailableSubject($mailable) : null,
                'message_id' => null,
                'preview' => null,
                'queued' => true,
                'job_id' => $payload['nightwatch']['job_id'] ?? (is_string($event->id ?? null) ? $event->id : null),
                'duration' => null,
                'error' => null,
            ]);
        });
    }

    public function sending(MessageSending $event): void
    {
        if (Guard::active() || ! WatchtowerAgent::feature('mail')) {
            return;
        }

        Guard::safely(function () use ($event): void {
            $message = $event->message;
            $headers = $message->getHeaders();
            $id = $headers->has(self::HEADER) ? (string) $headers->get(self::HEADER)?->getBodyAsString() : '';

            if ($id === '') {
                $id = $this->jobMailId ?? (string) Str::uuid();
                $this->jobMailId = null;
                $headers->addTextHeader(self::HEADER, $id);
            }

            if (count($this->started) > 100) {
                $this->started = $this->data = [];
            }

            $this->started[$id] = hrtime(true);
            $this->data[$id] = array_intersect_key($event->data, array_flip(['mailer', '__laravel_mailable', '__laravel_notification', '__laravel_notification_queued']));
            $this->instrumentTransport($event->data['mailer'] ?? null);

            $this->record($this->fromMessage($message, $event->data, $id, 'sending'));
        });
    }

    public function sent(MessageSent $event): void
    {
        if (Guard::active() || ! WatchtowerAgent::feature('mail')) {
            return;
        }

        Guard::safely(function () use ($event): void {
            $message = $event->sent->getOriginalMessage();

            if (! $message instanceof Email) {
                return;
            }

            $id = $this->mailId($message) ?? (string) Str::uuid();
            $record = $this->fromMessage($message, $event->data, $id, 'sent');
            unset($this->data[$id]);
            $record['message_id'] = $this->messageId($event);
            $record['duration'] = $this->elapsed($id);

            $this->record($record);
        });
    }

    public function transportFailed(RawMessage $message, Throwable $exception): void
    {
        if (Guard::active() || ! WatchtowerAgent::feature('mail') || ! $message instanceof Email) {
            return;
        }

        Guard::safely(function () use ($message, $exception): void {
            $id = $this->mailId($message) ?? (string) Str::uuid();
            $record = $this->fromMessage($message, $this->data[$id] ?? [], $id, 'failed');
            $record['duration'] = $this->elapsed($id);
            $record['error'] = Redactor::exception($exception);

            $this->record($record);
        });
    }

    public function jobStarted(CurrentJob $job): void
    {
        $this->job = $job;
        $this->jobMailId = $job->watchtower('mail_id');
    }

    public function jobFinished(CurrentJob $job, string $status): void
    {
        $this->job = null;
        $this->jobMailId = null;
        $this->started = [];
        $this->data = [];
    }

    /**
     * Wrap the mailer's Symfony transport once so transport exceptions are seen.
     */
    private function instrumentTransport(mixed $name): void
    {
        if (! config('watchtower-agent.mail.capture_failures', true) || ! $this->container->bound('mail.manager')) {
            return;
        }

        $mailer = $this->container->make('mail.manager')->mailer(is_string($name) ? $name : null);

        if ($mailer instanceof Mailer && ! $mailer->getSymfonyTransport() instanceof InstrumentedTransport) {
            $mailer->setSymfonyTransport(new InstrumentedTransport($mailer->getSymfonyTransport(), $this));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromMessage(Email $message, array $data, string $id, string $stage): array
    {
        $mailer = (string) ($data['mailer'] ?? config('mail.default', ''));
        $notification = $data['__laravel_notification'] ?? null;
        $job = $this->job;

        return [
            'mail_id' => $id,
            'stage' => $stage,
            'mailable' => is_string($data['__laravel_mailable'] ?? null) ? $data['__laravel_mailable'] : null,
            'notification' => is_string($notification) ? $notification : null,
            'mailer' => $mailer,
            'transport' => $this->transport($mailer),
            'from' => $this->first($this->symfonyAddresses($message->getFrom())),
            'to' => $this->addresses($this->symfonyAddresses($message->getTo())),
            'cc' => $this->addresses($this->symfonyAddresses($message->getCc())),
            'bcc' => $this->bcc($this->symfonyAddresses($message->getBcc())),
            'subject' => $message->getSubject(),
            'message_id' => null,
            'preview' => $this->preview($message),
            'queued' => $job !== null || (bool) ($data['__laravel_notification_queued'] ?? false),
            'job_id' => $job?->jobId(),
            'duration' => null,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(array $payload): void
    {
        $this->container->make(Recorder::class)->inExecution(
            'wt-mail',
            $payload,
            (int) config('watchtower-agent.mail.max_per_execution', 100),
        );
    }

    private function mailId(Message $message): ?string
    {
        $header = $message->getHeaders()->get(self::HEADER);
        $id = $header?->getBodyAsString();

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function messageId(MessageSent $event): ?string
    {
        try {
            $id = $event->sent->getMessageId();
        } catch (Throwable) {
            return null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function elapsed(string $id): ?int
    {
        if (! isset($this->started[$id])) {
            return null;
        }

        $duration = (int) ((hrtime(true) - $this->started[$id]) / 1000);
        unset($this->started[$id], $this->data[$id]);

        return $duration;
    }

    private function transport(string $mailer): string
    {
        return (string) config("mail.mailers.{$mailer}.transport", '');
    }

    private function preview(Email $message): ?string
    {
        if (! config('watchtower-agent.mail.preview', false)) {
            return null;
        }

        $text = $message->getTextBody();

        if (! is_string($text) || trim($text) === '') {
            $html = $message->getHtmlBody();
            $text = is_string($html) ? trim((string) preg_replace('/\s+/', ' ', strip_tags($html))) : '';
        }

        return $text === '' ? null : mb_substr(Redactor::text($text), 0, 500);
    }

    private function mailableSubject(object $mailable): ?string
    {
        if (is_string($mailable->subject ?? null)) {
            return $mailable->subject;
        }

        if (method_exists($mailable, 'envelope')) {
            $subject = $mailable->envelope()->subject ?? null;

            return is_string($subject) ? $subject : null;
        }

        return null;
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return list<string>
     */
    private function symfonyAddresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): string => $address->getAddress(), $addresses));
    }

    /**
     * Mailable recipients are arrays of ['name' => ..., 'address' => ...].
     *
     * @param  array<int, mixed>  $recipients
     * @return list<string>
     */
    private function mailableAddresses(array $recipients): array
    {
        $addresses = [];

        foreach ($recipients as $recipient) {
            $address = is_array($recipient) ? ($recipient['address'] ?? null) : $recipient;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return match ($this->recipientMode()) {
            'none' => [],
            'full' => $addresses,
            default => array_map(Redactor::email(...), $addresses),
        };
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function bcc(array $addresses): array
    {
        return config('watchtower-agent.mail.include_bcc', false) ? $this->addresses($addresses) : [];
    }

    /**
     * @param  list<string>  $addresses
     */
    private function first(array $addresses): ?string
    {
        if ($addresses === [] || $this->recipientMode() === 'none') {
            return null;
        }

        return $this->addresses([$addresses[0]])[0] ?? null;
    }

    private function recipientMode(): string
    {
        $mode = config('watchtower-agent.mail.recipients', 'masked');

        return in_array($mode, ['none', 'masked', 'full'], true) ? $mode : 'masked';
    }
}
