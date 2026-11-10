<?php

namespace Cyberwizard\WatchtowerAgent\Broadcasting;

use Closure;
use Cyberwizard\WatchtowerAgent\Events\EventRecorder;
use Cyberwizard\WatchtowerAgent\Queue\CurrentJob;
use Cyberwizard\WatchtowerAgent\Queue\JobLifecycle;
use Cyberwizard\WatchtowerAgent\Queue\QueuePayload;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\enum_value;

/**
 * wt-broadcast: "queued" when a BroadcastEvent job is pushed, "sent" when a driver accepted the
 * publish call, "failed" when it threw (or the queued job failed before reaching a driver).
 * Delivery to clients is never asserted.
 */
class BroadcastRecorder
{
    public function __construct(private Container $container) {}

    /**
     * Register a decorating creator for every configured broadcast driver that Laravel or the
     * app can create. Runs when the BroadcastManager is resolved and again once the app has
     * booted, so a Broadcast::extend() made in a service provider's boot() is wrapped too.
     * Connections resolved before that keep their undecorated driver.
     */
    public function install(BroadcastManager $manager): void
    {
        Guard::safely(function () use ($manager): void {
            $creators = Closure::bind(fn (): array => $this->customCreators, $manager, BroadcastManager::class)();
            $create = Closure::bind(
                fn (string $driver, array $config) => $this->{'create'.ucfirst($driver).'Driver'}($config),
                $manager,
                BroadcastManager::class,
            );
            $drivers = ['null'];

            foreach ((array) config('broadcasting.connections', []) as $connection) {
                if (is_array($connection) && is_string($connection['driver'] ?? null)) {
                    $drivers[] = $connection['driver'];
                }
            }

            foreach (array_unique($drivers) as $driver) {
                $existing = $creators[$driver] ?? null;

                if ($existing instanceof Closure && $this->isOurs($existing)) {
                    continue;
                }

                if ($existing === null && ! method_exists($manager, 'create'.ucfirst($driver).'Driver')) {
                    continue;
                }

                $recorder = $this;

                $manager->extend($driver, static function ($app, array $config) use ($create, $driver, $existing, $recorder) {
                    $inner = $existing !== null ? $existing($app, $config) : $create($driver, $config);

                    if (! $inner instanceof Broadcaster || $inner instanceof InstrumentedBroadcaster) {
                        return $inner;
                    }

                    return new InstrumentedBroadcaster($inner, $recorder, $recorder->connectionName($config), $driver);
                });
            }
        });
    }

    /**
     * JobQueued: a BroadcastEvent was pushed onto the queue.
     */
    public function queued(JobQueued $event): void
    {
        if (! $event->job instanceof BroadcastEvent || Guard::active() || ! WatchtowerAgent::feature('broadcasts')) {
            return;
        }

        Guard::safely(function () use ($event): void {
            $payload = QueuePayload::fromQueuedEvent($event);
            $broadcast = $event->job->event;
            $frame = $this->container->make(EventRecorder::class)->current();
            try {
                $channels = $this->channels(Arr::wrap($broadcast->broadcastOn()));
            } catch (Throwable) {
                $channels = []; // the worker will fail the job and record it
            }

            [$json, $truncated] = $this->payload($this->broadcastPayload($broadcast));
            $connections = method_exists($broadcast, 'broadcastConnections') ? $broadcast->broadcastConnections() : [null];

            foreach ($connections as $connection) {
                $connection = (string) (enum_value($connection) ?: config('broadcasting.default', 'null'));
                $driver = (string) config("broadcasting.connections.{$connection}.driver", $connection);

                if ($this->ignored($driver)) {
                    continue;
                }

                $this->record([
                    'broadcast_id' => $payload['watchtower']['broadcast_id'] ?? (string) Str::uuid(),
                    'event_id' => $frame?->id,
                    'name' => $this->name($broadcast),
                    'class' => $broadcast::class,
                    'connection' => $connection,
                    'driver' => $driver,
                    'channels' => $channels,
                    'queued' => true,
                    'job_id' => $payload['nightwatch']['job_id'] ?? (is_string($event->id ?? null) ? $event->id : null),
                    'stage' => 'queued',
                    'duration' => null,
                    'payload' => $json,
                    'truncated' => $truncated,
                    'exception' => null,
                    'socket_excluded' => ($broadcast->socket ?? null) !== null,
                ]);
            }
        });
    }

    /**
     * A decorated driver's broadcast() returned (sent) or threw (failed).
     *
     * @param  array<int, mixed>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function attempted(InstrumentedBroadcaster $broadcaster, array $channels, string $name, array $payload, int $duration, ?Throwable $exception = null): void
    {
        if (Guard::active() || ! WatchtowerAgent::feature('broadcasts') || $this->ignored($broadcaster->driver)) {
            return;
        }

        Guard::safely(function () use ($broadcaster, $channels, $name, $payload, $duration, $exception): void {
            $job = $this->broadcastJob();
            $frame = $this->container->make(EventRecorder::class)->current();
            $socket = $payload['socket'] ?? null;
            unset($payload['socket']);
            [$json, $truncated] = $this->payload($payload);

            if ($job !== null) {
                $job->broadcastAttempted = true;
            }

            $this->record([
                'broadcast_id' => $job?->watchtower('broadcast_id') ?? (string) Str::uuid(),
                'event_id' => $job !== null ? $this->jobEventId($job) : $frame?->id,
                'name' => $name,
                'class' => $job !== null
                    ? (string) ($job->payload['displayName'] ?? $name)
                    : ($frame !== null && $frame->broadcast ? (string) $frame->class : $name),
                'connection' => $broadcaster->connection,
                'driver' => $broadcaster->driver,
                'channels' => $this->channels($channels),
                'queued' => $job !== null,
                'job_id' => $job?->jobId(),
                'stage' => $exception === null ? 'sent' : 'failed',
                'duration' => $duration,
                'payload' => $json,
                'truncated' => $truncated,
                'exception' => $exception === null ? null : Redactor::exception($exception),
                'socket_excluded' => $socket !== null,
            ]);
        });
    }

    /**
     * A queued BroadcastEvent failed before any driver was called (for example broadcastOn()
     * threw): record the failure so the queued stage doesn't dangle.
     */
    public function jobFinished(CurrentJob $job, string $status): void
    {
        if ($status !== 'failed' || $job->broadcastAttempted || ! $this->isBroadcastJob($job) || ! WatchtowerAgent::feature('broadcasts')) {
            return;
        }

        $class = (string) ($job->payload['displayName'] ?? 'unknown');
        $connection = (string) config('broadcasting.default', 'null');

        $this->record([
            'broadcast_id' => $job->watchtower('broadcast_id') ?? (string) Str::uuid(),
            'event_id' => $this->jobEventId($job),
            'name' => $class,
            'class' => $class,
            'connection' => $connection,
            'driver' => (string) config("broadcasting.connections.{$connection}.driver", $connection),
            'channels' => [],
            'queued' => true,
            'job_id' => $job->jobId(),
            'stage' => 'failed',
            'duration' => null,
            'payload' => null,
            'truncated' => false,
            'exception' => $job->exception === null ? null : Redactor::exception($job->exception),
            'socket_excluded' => false,
        ]);
    }

    private function isOurs(Closure $creator): bool
    {
        return ((new \ReflectionFunction($creator))->getStaticVariables()['recorder'] ?? null) instanceof self;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function connectionName(array $config): string
    {
        foreach ((array) config('broadcasting.connections', []) as $name => $candidate) {
            if ($candidate === $config) {
                return (string) $name;
            }
        }

        return (string) ($config['driver'] ?? 'null');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(array $payload): void
    {
        $this->container->make(Recorder::class)->inExecution(
            'wt-broadcast',
            $payload,
            (int) config('watchtower-agent.broadcasts.max_per_execution', 100),
        );
    }

    private function broadcastJob(): ?CurrentJob
    {
        $job = $this->container->make(JobLifecycle::class)->current();

        return $job !== null && $this->isBroadcastJob($job) ? $job : null;
    }

    private function isBroadcastJob(CurrentJob $job): bool
    {
        $command = $job->commandName();

        return $command !== null && is_a($command, BroadcastEvent::class, true);
    }

    private function jobEventId(CurrentJob $job): ?string
    {
        return isset($job->event['id']) && is_string($job->event['id']) ? $job->event['id'] : null;
    }

    private function ignored(string $driver): bool
    {
        return in_array($driver, (array) config('watchtower-agent.broadcasts.ignore_drivers', ['null']), true);
    }

    private function name(object $event): string
    {
        return method_exists($event, 'broadcastAs') ? (string) enum_value($event->broadcastAs()) : $event::class;
    }

    /**
     * What BroadcastEvent::getPayloadFromEvent() would send.
     *
     * @return array<string, mixed>
     */
    private function broadcastPayload(object $event): array
    {
        if (method_exists($event, 'broadcastWith') && is_array($with = $event->broadcastWith())) {
            return Redactor::redact((array) Redactor::normalize($with));
        }

        $payload = Redactor::eventPayload($event);
        unset($payload['broadcastQueue'], $payload['socket']);

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{0: string|null, 1: bool}
     */
    private function payload(array $payload): array
    {
        if (! config('watchtower-agent.broadcasts.capture_payload', true)) {
            return [null, false];
        }

        return Redactor::json(
            Redactor::redact((array) Redactor::normalize($payload)),
            (int) config('watchtower-agent.broadcasts.max_payload_bytes', 8192),
        );
    }

    /**
     * @param  array<int, mixed>  $channels
     * @return list<array{name: string, type: string}>
     */
    private function channels(array $channels): array
    {
        $list = [];

        foreach ($channels as $channel) {
            $name = (string) $channel;

            $list[] = [
                'name' => $name,
                'type' => match (true) {
                    $channel instanceof PresenceChannel, str_starts_with($name, 'presence-') => 'presence',
                    $channel instanceof PrivateChannel, str_starts_with($name, 'private-') => 'private',
                    default => 'public',
                },
            ];
        }

        return $list;
    }
}
