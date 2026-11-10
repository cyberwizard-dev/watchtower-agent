<?php

namespace Cyberwizard\WatchtowerAgent\Events;

use Closure;
use Cyberwizard\WatchtowerAgent\Records\ExecutionLimiter;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds wt-event records. Frames are opened and closed by InstrumentedDispatcher; with
 * listener timing unavailable, dispatched() records the dispatch alone (no listeners).
 *
 * The event id of the dispatch being handled is kept in hidden Context under
 * "watchtower_event" so queued listeners and queued broadcasts can carry it to the worker.
 */
class EventRecorder
{
    public const CONTEXT_EVENT = 'watchtower_event';

    public const CONTEXT_LISTENER = 'watchtower_listener';

    public const DEFAULT_DENY = [
        'Illuminate\\*',
        'Laravel\\*',
        'Cyberwizard\\WatchtowerAgent\\*',
        'eloquent.*',
        'bootstrapped:*',
        'bootstrapping:*',
        'creating:*',
        'composing:*',
    ];

    private const MAX_LISTENERS = 50;

    /** @var list<EventFrame|null> */
    private array $frames = [];

    /** @var array<string, bool> */
    private array $decisions = [];

    /** @var array{execution: string|null, names: array<string, int>} */
    private array $dropped = ['execution' => null, 'names' => []];

    /**
     * "instrumented" when listeners are timed, "dispatch-only" when only dispatches are seen.
     */
    public string $mode = 'dispatch-only';

    public function __construct(private Container $container) {}

    /**
     * Open a frame for a dispatch. Returns null (and pushes a placeholder so nested
     * dispatches stay balanced) when the event is not captured.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function begin(string $name, array $payload): ?EventFrame
    {
        // Fast path: most dispatches are framework events, denied by a cached decision.
        $frame = Guard::active() || ! $this->captures($name)
            ? null
            : Guard::safely(fn () => $this->open($name, $payload));

        $this->frames[] = $frame;

        return $frame;
    }

    public function end(?EventFrame $frame): void
    {
        array_pop($this->frames);

        if ($frame === null) {
            return;
        }

        Guard::safely(function () use ($frame): void {
            $this->restoreContext($frame->previousContext);
            $this->emit($frame, (int) ((hrtime(true) - $frame->startedAt) / 1000));
        });
    }

    /**
     * Run one listener, timing it when it belongs to the frame being captured.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function runListener(mixed $listener, Closure $made, mixed $event, array $payload): mixed
    {
        $frame = $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];

        if ($frame === null) {
            return $made($event, $payload);
        }

        $queued = ListenerName::queued($listener);

        if ($queued) {
            return $this->runQueuedListener($frame, $listener, $made, $event, $payload);
        }

        $started = hrtime(true);

        try {
            $response = $made($event, $payload);
        } catch (Throwable $exception) {
            $this->addListener($frame, $listener, false, $started, 'failed', $exception);

            throw $exception;
        }

        $this->addListener($frame, $listener, false, $started, 'ok');

        return $response;
    }

    /**
     * Dispatch-only capture, used when the dispatcher can't be instrumented: a wildcard
     * listener records the event without listener details.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function dispatched(string $name, array $payload = []): void
    {
        if (Guard::active()) {
            return;
        }

        $frame = Guard::safely(fn () => $this->open($name, $payload, false));

        if ($frame !== null) {
            Guard::safely(fn () => $this->emit($frame, 0));
        }
    }

    /**
     * The innermost captured dispatch, if any.
     */
    public function current(): ?EventFrame
    {
        for ($index = count($this->frames) - 1; $index >= 0; $index--) {
            if ($this->frames[$index] !== null) {
                return $this->frames[$index];
            }
        }

        return null;
    }

    /**
     * Emit wt-event-summary for events dropped past the per-execution cap. Called at the end
     * of every request, job, command and scheduled task, before Nightwatch digests it.
     */
    public function flushSummary(): void
    {
        Guard::safely(function (): void {
            $dropped = $this->dropped;
            $this->dropped = ['execution' => null, 'names' => []];

            if ($dropped['names'] === []) {
                return;
            }

            $execution = $this->recorder()->execution();

            if ($execution === null || $execution['execution_id'] !== $dropped['execution']) {
                return;
            }

            arsort($dropped['names']);

            $names = [];

            foreach (array_slice($dropped['names'], 0, 50, true) as $eventName => $count) {
                $names[] = ['name' => (string) $eventName, 'count' => $count];
            }

            $this->recorder()->inExecution('wt-event-summary', [
                'dropped' => array_sum($dropped['names']),
                'names' => $names,
            ]);
        });
    }

    public function captures(string $name): bool
    {
        return $this->decisions[$name] ??= (bool) Guard::safely(fn () => $this->decide($name), false);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function open(string $name, array $payload, bool $withContext = true): ?EventFrame
    {
        // The caller checked Guard::active() before raising the guard around this call.
        if (! WatchtowerAgent::feature('events') || ! $this->captures($name)) {
            return null;
        }

        $rate = (float) config('watchtower-agent.events.sample_rate', 1.0);

        if ($rate < 1.0 && (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) > $rate) {
            return null;
        }

        $execution = $this->recorder()->execution();

        if ($execution === null) {
            return null;
        }

        $max = (int) config('watchtower-agent.events.max_per_execution', 50);

        if (! $this->container->make(ExecutionLimiter::class)->allow('events.cap', $execution['execution_id'], $max)) {
            if ($this->dropped['execution'] !== $execution['execution_id']) {
                $this->dropped = ['execution' => $execution['execution_id'], 'names' => []];
            }

            $this->dropped['names'][$name] = ($this->dropped['names'][$name] ?? 0) + 1;

            return null;
        }

        $object = $payload[0] ?? null;
        $isObject = is_object($object) && $object::class === $name;

        [$json, $truncated] = config('watchtower-agent.events.capture_payload', true)
            ? Redactor::json(
                Redactor::eventPayload($isObject ? $object : $name, $isObject ? [] : $payload),
                (int) config('watchtower-agent.events.max_payload_bytes', 8192),
            )
            : [null, false];

        $id = (string) Str::uuid();
        $previous = null;

        if ($withContext) {
            $previous = Context::getHidden(self::CONTEXT_EVENT);
            Context::addHidden(self::CONTEXT_EVENT, ['id' => $id, 'name' => $name]);
        }

        return new EventFrame(
            id: $id,
            name: $name,
            class: $isObject ? $name : null,
            broadcast: $isObject && $object instanceof ShouldBroadcast,
            payload: $json,
            truncated: $truncated,
            startedAt: hrtime(true),
            previousContext: $previous,
        );
    }

    private function emit(EventFrame $frame, int $duration): void
    {
        $this->recorder()->inExecution('wt-event', [
            'event_id' => $frame->id,
            'group' => hash('xxh128', $frame->name),
            'name' => $frame->name,
            'class' => $frame->class,
            'broadcast' => $frame->broadcast,
            'queued_listeners' => $frame->queuedListeners,
            'listeners' => $frame->listeners,
            'payload' => $frame->payload,
            'truncated' => $frame->truncated,
            'duration' => $duration,
        ]);
    }

    /**
     * Queued listeners are pushed, not run: record them as queued and put their name in hidden
     * Context so the CallQueuedListener job payload carries it (with the event id) to the worker.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function runQueuedListener(EventFrame $frame, mixed $listener, Closure $made, mixed $event, array $payload): mixed
    {
        $frame->queuedListeners++;

        $name = ListenerName::for($listener);
        $previous = Guard::safely(function () use ($name) {
            $previous = Context::getHidden(self::CONTEXT_LISTENER);
            Context::addHidden(self::CONTEXT_LISTENER, $name);

            return $previous;
        });

        try {
            $response = $made($event, $payload);
        } catch (Throwable $exception) {
            $this->addListener($frame, $listener, true, null, 'failed', $exception);

            throw $exception;
        } finally {
            Guard::safely(fn () => $previous === null
                ? Context::forgetHidden(self::CONTEXT_LISTENER)
                : Context::addHidden(self::CONTEXT_LISTENER, $previous));
        }

        $this->addListener($frame, $listener, true, null, 'queued');

        return $response;
    }

    private function addListener(EventFrame $frame, mixed $listener, bool $queued, ?int $started, string $status, ?Throwable $exception = null): void
    {
        Guard::safely(function () use ($frame, $listener, $queued, $started, $status, $exception): void {
            if (count($frame->listeners) >= self::MAX_LISTENERS) {
                $frame->omittedListeners++;

                return;
            }

            $frame->listeners[] = [
                'listener' => ListenerName::for($listener),
                'queued' => $queued,
                'duration' => $started === null ? null : (int) ((hrtime(true) - $started) / 1000),
                'status' => $status,
                'exception' => $exception === null ? null : Redactor::exception($exception),
            ];
        });
    }

    private function restoreContext(mixed $previous): void
    {
        $previous === null
            ? Context::forgetHidden(self::CONTEXT_EVENT)
            : Context::addHidden(self::CONTEXT_EVENT, $previous);
    }

    private function recorder(): Recorder
    {
        return $this->container->make(Recorder::class);
    }

    private function decide(string $name): bool
    {
        $allow = (array) config('watchtower-agent.events.allow', []);
        $deny = array_merge(
            config('watchtower-agent.events.default_deny', true) ? self::DEFAULT_DENY : [],
            (array) config('watchtower-agent.events.deny', []),
        );

        if ($allow !== [] && Str::is($allow, $name)) {
            return true;
        }

        if ($allow !== []) {
            return false;
        }

        return ! Str::is($deny, $name);
    }
}
