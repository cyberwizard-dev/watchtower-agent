<?php

namespace Cyberwizard\WatchtowerAgent\Queue;

use Illuminate\Contracts\Queue\Job;
use Throwable;

/**
 * The job a worker is processing right now.
 */
final class CurrentJob
{
    public ?Throwable $exception = null;

    /**
     * Hidden Context the job was dispatched with (hydrated by Laravel before JobProcessing
     * listeners run): the event that queued it and the queued listener's display name.
     *
     * @var array{id?: string, name?: string}|null
     */
    public ?array $event = null;

    public ?string $listener = null;

    /** Set by BroadcastRecorder once a driver attempt was recorded for this job. */
    public bool $broadcastAttempted = false;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly Job $job,
        public readonly array $payload,
        public readonly int $startedAt,
    ) {}

    public function commandName(): ?string
    {
        $name = $this->payload['data']['commandName'] ?? null;

        return is_string($name) ? $name : null;
    }

    public function jobId(): ?string
    {
        $id = $this->payload['nightwatch']['job_id'] ?? null;

        if (is_string($id) && $id !== '') {
            return $id;
        }

        try {
            $uuid = $this->job->uuid();
        } catch (Throwable) {
            return null;
        }

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    public function attempt(): int
    {
        try {
            return max(1, (int) $this->job->attempts());
        } catch (Throwable) {
            return 1;
        }
    }

    public function watchtower(string $key): ?string
    {
        $value = $this->payload['watchtower'][$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A string property of the serialized command, read without unserialising it (which
     * could run model queries). Returns null for encrypted commands.
     */
    public function serializedString(string $property): ?string
    {
        $command = $this->payload['data']['command'] ?? null;

        if (! is_string($command)) {
            return null;
        }

        $length = strlen($property);

        return preg_match('/s:'.$length.':"'.preg_quote($property, '/').'";s:\d+:"([^"]*)"/', $command, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * The class of the first object in the serialized command's "data" array (the event of a
     * CallQueuedListener).
     */
    public function serializedEventClass(): ?string
    {
        $command = $this->payload['data']['command'] ?? null;

        if (! is_string($command)) {
            return null;
        }

        return preg_match('/s:4:"data";a:\d+:\{i:0;O:\d+:"([^"]+)"/', $command, $matches) === 1 ? $matches[1] : null;
    }
}
