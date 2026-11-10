<?php

namespace Cyberwizard\WatchtowerAgent\Records;

use Closure;
use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;

/**
 * Wraps a payload in the common header and hands it to the transport.
 */
class Recorder
{
    public function __construct(
        private RecordFactory $factory,
        private RecordTransport $transport,
        private ExecutionLimiter $limiter,
    ) {}

    /**
     * Out-of-band record: sent immediately with writeNow().
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $type, array $payload): bool
    {
        if (! WatchtowerAgent::enabled()) {
            return false;
        }

        return $this->transport->send($this->factory->make($type, $payload));
    }

    /**
     * In-execution enrichment: buffered with the current execution.
     *
     * @param  array<string, mixed>  $payload
     */
    public function buffer(string $type, array $payload): void
    {
        if (! WatchtowerAgent::enabled()) {
            return;
        }

        $this->transport->buffer($this->factory->make($type, $payload));
    }

    /**
     * The current execution header (trace_id, execution_source, execution_id, execution_stage,
     * user), or null when Nightwatch is not capturing.
     *
     * @return array{trace_id: string, execution_source: string, execution_id: string, execution_stage: string, user: string}|null
     */
    public function execution(): ?array
    {
        if (! WatchtowerAgent::enabled()) {
            return null;
        }

        return Guard::safely(fn () => $this->transport->execution());
    }

    /**
     * In-execution record that carries the execution header. Buffered, so it follows
     * Nightwatch's sampling, and capped per execution at $limit records of this type
     * (0 = no cap). Returns false when the record was not buffered.
     *
     * @param  array<string, mixed>|(Closure(): array<string, mixed>)  $payload  a closure is only built when the record will be kept
     */
    public function inExecution(string $type, array|Closure $payload, int $limit = 0): bool
    {
        $execution = $this->execution();

        if ($execution === null || ! $this->limiter->allow($type, $execution['execution_id'], $limit)) {
            return false;
        }

        return Guard::safely(function () use ($type, $payload, $execution): bool {
            $payload = $payload instanceof Closure ? $payload() : $payload;

            $this->transport->buffer($this->factory->make($type, [...$execution, ...$payload]));

            return true;
        }, false);
    }

    /**
     * Like inExecution(), but sent immediately with writeNow() instead of buffered, so the
     * record reaches Watch Tower even when Nightwatch does not sample the execution (buffers of
     * unsampled executions are dropped). For security signals that must never be sampled away;
     * callers throttle them. Returns false when the record was not handed over.
     *
     * @param  array<string, mixed>|(Closure(): array<string, mixed>)  $payload  a closure is only built when the record will be sent
     */
    public function sendInExecution(string $type, array|Closure $payload, int $limit = 0): bool
    {
        $execution = $this->execution();

        if ($execution === null || ! $this->limiter->allow($type, $execution['execution_id'], $limit)) {
            return false;
        }

        return Guard::safely(function () use ($type, $payload, $execution): bool {
            $payload = $payload instanceof Closure ? $payload() : $payload;

            return $this->transport->send($this->factory->make($type, [...$execution, ...$payload]));
        }, false);
    }

    /**
     * Force Nightwatch to keep the current execution regardless of its sample rate.
     */
    public function sampleExecution(): void
    {
        if (! WatchtowerAgent::enabled()) {
            return;
        }

        Guard::safely(fn () => $this->transport->sample());
    }
}
