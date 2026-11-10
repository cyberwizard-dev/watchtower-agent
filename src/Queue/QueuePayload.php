<?php

namespace Cyberwizard\WatchtowerAgent\Queue;

use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;

/**
 * Adds a "watchtower" key to queued broadcast and mail job payloads with the id the queued
 * stage was recorded under, so the worker's sent/failed stages share it.
 */
final class QueuePayload
{
    private static int $generation = 0;

    /**
     * Register the payload hook. Queue callbacks are static and outlive the application (tests
     * boot many); only the most recently registered hook acts, so there are never duplicates.
     */
    public static function register(): void
    {
        $generation = ++self::$generation;

        Queue::createPayloadUsing(static function ($connection, $queue, array $payload) use ($generation): array {
            if ($generation !== self::$generation) {
                return [];
            }

            return Guard::safely(static fn (): array => self::extra($payload), []);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function extra(array $payload): array
    {
        // While the hooks run, Laravel still holds the job object here (the class name and the
        // serialized command replace it afterwards).
        $command = $payload['data']['commandName'] ?? null;
        $command = is_object($command) ? $command::class : $command;
        $extra = [];

        if (! is_string($command)) {
            return [];
        }

        if (is_a($command, BroadcastEvent::class, true) && WatchtowerAgent::feature('broadcasts')) {
            $extra['broadcast_id'] = (string) Str::uuid();
        }

        if (in_array($command, [SendQueuedMailable::class, SendQueuedNotifications::class], true) && WatchtowerAgent::feature('mail')) {
            $extra['mail_id'] = (string) Str::uuid();
        }

        if ($extra === []) {
            return [];
        }

        $existing = is_array($payload['watchtower'] ?? null) ? $payload['watchtower'] : [];

        return ['watchtower' => [...$existing, ...$extra]];
    }

    /**
     * The decoded payload of a JobQueued event (a JSON string before Laravel 11.x added
     * payload()).
     *
     * @return array<string, mixed>
     */
    public static function fromQueuedEvent(object $event): array
    {
        if (method_exists($event, 'payload')) {
            $payload = $event->payload();

            return is_array($payload) ? $payload : [];
        }

        $raw = $event->payload ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : [];
    }
}
