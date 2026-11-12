<?php

namespace Cyberwizard\WatchtowerAgent\Notifications;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Stringable;
use Throwable;

/**
 * wt-notification: one record per (notifiable, channel) a notification was sent to or failed
 * for, with the recipient Nightwatch never reports. Nightwatch's own notification record only
 * carries the execution's signed-in user and always says `failed: false`.
 *
 * The recipient is described by its type, key and a label (a configured attribute, else
 * "Type #key"); its route on that channel (e-mail address, phone number, webhook…) is sent as
 * the app resolves it unless notifications.recipient_routes is false. Sent records are
 * buffered with the execution; failures are sent immediately so sampling never drops them.
 */
class NotificationRecorder
{
    /**
     * Channels whose "route" is not a recipient address.
     *
     * @var list<string>
     */
    private const ROUTELESS_CHANNELS = ['database', 'broadcast'];

    private const MAX_ERROR = 1000;

    /** @var array<string, int> */
    private array $started = [];

    public function __construct(private Container $container) {}

    public function sending(NotificationSending $event): void
    {
        if (Guard::active() || ! WatchtowerAgent::feature('notifications')) {
            return;
        }

        if (count($this->started) > 200) {
            $this->started = [];
        }

        $this->started[$this->key($event)] = hrtime(true);
    }

    public function sent(NotificationSent $event): void
    {
        $this->record($event, 'sent', null);
    }

    public function failed(NotificationFailed $event): void
    {
        $this->record($event, 'failed', $event->data);
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     */
    private function record(NotificationSent|NotificationFailed $event, string $status, ?array $data): void
    {
        if (Guard::active() || ! WatchtowerAgent::feature('notifications')) {
            return;
        }

        $key = $this->key($event);
        $started = $this->started[$key] ?? null;
        unset($this->started[$key]);

        Guard::safely(function () use ($event, $status, $data, $started): void {
            $payload = fn (): array => $this->payload($event, $status, $data, $started);
            $limit = (int) config('watchtower-agent.notifications.max_per_execution', 100);
            $recorder = $this->container->make(Recorder::class);

            $status === 'failed'
                ? $recorder->sendInExecution('wt-notification', $payload, $limit)
                : $recorder->inExecution('wt-notification', $payload, $limit);
        });
    }

    /**
     * @param  array<array-key, mixed>|null  $data
     * @return array<string, mixed>
     */
    private function payload(NotificationSent|NotificationFailed $event, string $status, ?array $data, ?int $started): array
    {
        $notifiable = $event->notifiable;
        $notification = $event->notification;
        $channel = is_string($event->channel) ? $event->channel : (is_object($event->channel) ? $event->channel::class : 'unknown');
        $route = $this->route($notifiable, $channel, $notification);
        $class = is_object($notifiable) ? $notifiable::class : null;
        $type = $class !== null ? class_basename($class) : null;
        $notifiableKey = $this->notifiableKey($notifiable, $route);
        $id = is_object($notification) ? ($notification->id ?? null) : null;

        return [
            'notification_id' => is_scalar($id) ? mb_substr((string) $id, 0, 64) : null,
            'notification' => is_object($notification) ? self::className($notification) : 'unknown',
            'channel' => mb_substr($channel, 0, 255),
            'status' => $status,
            'notifiable_type' => $type,
            'notifiable_class' => $class,
            'notifiable_key' => $notifiableKey,
            'label' => $this->label($notifiable, $type, $notifiableKey, $route),
            'route' => $route,
            'duration' => $started !== null ? (int) ((hrtime(true) - $started) / 1000) : null,
            'error' => $status === 'failed' ? $this->error($data ?? []) : null,
        ];
    }

    /**
     * The recipient's route on the channel as a string, or null when routes are off, the
     * channel has no address-like route, or the app returns something that is not one.
     */
    private function route(mixed $notifiable, string $channel, mixed $notification): ?string
    {
        if (! config('watchtower-agent.notifications.recipient_routes', true) || in_array($channel, self::ROUTELESS_CHANNELS, true)) {
            return null;
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            $value = $notifiable->routes[$channel] ?? null;
        } elseif (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            try {
                $value = $notifiable->routeNotificationFor($channel, $notification);
            } catch (Throwable) {
                return null;
            }
        } else {
            return null;
        }

        return self::routeString($value);
    }

    /**
     * Strings, numbers and lists of them (mail routes may be [address => name]).
     */
    private static function routeString(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || $value instanceof Stringable) {
            $value = trim((string) $value);

            return $value === '' ? null : mb_substr($value, 0, 255);
        }

        if (! is_array($value)) {
            return null;
        }

        $parts = [];

        foreach ($value as $key => $item) {
            $part = is_string($key) ? $key : (is_string($item) || is_int($item) ? (string) $item : null);

            if ($part !== null && trim($part) !== '') {
                $parts[] = trim($part);
            }
        }

        return $parts === [] ? null : mb_substr(implode(', ', $parts), 0, 255);
    }

    private function notifiableKey(mixed $notifiable, ?string $route): ?string
    {
        if ($notifiable instanceof Model) {
            $key = $notifiable->getKey();

            return is_scalar($key) || $key instanceof Stringable ? mb_substr((string) $key, 0, 255) : null;
        }

        return $notifiable instanceof AnonymousNotifiable ? $route : null;
    }

    /**
     * The configured attribute, read from the model's loaded attributes only (no accessor,
     * relation or query runs), else "Type #key", the route of an on-demand notification or
     * the type alone.
     */
    private function label(mixed $notifiable, ?string $type, ?string $key, ?string $route): ?string
    {
        $attribute = config('watchtower-agent.notifications.label_attribute', 'name');

        if ($notifiable instanceof Model && is_string($attribute) && $attribute !== '' && ! Redactor::sensitiveKey($attribute)) {
            $value = $notifiable->getAttributes()[$attribute] ?? null;

            if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 255);
            }
        }

        return match (true) {
            $notifiable instanceof AnonymousNotifiable => $route ?? 'On-demand',
            $type !== null && $key !== null => "{$type} #{$key}",
            default => $type,
        };
    }

    /**
     * The failure reason: the exception NotificationSender reports, else a message the
     * channel put in the event data. Secrets are redacted and the text is capped.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function error(array $data): ?string
    {
        $exception = $data['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $text = $exception::class.': '.Redactor::text($exception->getMessage());
        } else {
            $message = $data['message'] ?? $data['error'] ?? $data['reason'] ?? null;
            $text = is_string($message) && trim($message) !== '' ? Redactor::text(trim($message)) : null;
        }

        if ($text === null) {
            return null;
        }

        return mb_strlen($text) > self::MAX_ERROR ? mb_substr($text, 0, self::MAX_ERROR - 1).'…' : $text;
    }

    /**
     * Anonymous notification classes are reported like Nightwatch does (up to the NUL byte).
     */
    private static function className(object $notification): string
    {
        $class = $notification::class;

        return str_contains($class, "@anonymous\0") ? (string) strstr($class, "\0", true) : $class;
    }

    private function key(NotificationSending|NotificationSent|NotificationFailed $event): string
    {
        $channel = is_string($event->channel) ? $event->channel : '';

        return (is_object($event->notification) ? spl_object_id($event->notification) : 0)
            .'|'.(is_object($event->notifiable) ? spl_object_id($event->notifiable) : 0)
            .'|'.$channel;
    }
}
