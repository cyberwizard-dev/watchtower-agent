<?php

namespace Cyberwizard\WatchtowerAgent\Workers;

use BackedEnum;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Throwable;
use UnitEnum;

/**
 * Sends wt-worker heartbeats for the queue worker running in this process. Bound as a singleton
 * so state lives for the worker's lifetime.
 */
class WorkerHeartbeat
{
    private ?float $startedAt = null;

    private ?float $lastBeatAt = null;

    private string $connection = '';

    private string $queue = '';

    private int $jobsProcessed = 0;

    /**
     * @var (callable(): float)|null
     */
    public $clock = null;

    public function __construct(private Recorder $recorder) {}

    public function starting(WorkerStarting $event): void
    {
        $this->guard(function () use ($event): void {
            $this->begin((string) $event->connectionName, (string) $event->queue);
            $this->beat('starting');
        });
    }

    /**
     * Looping is dispatched with Dispatcher::until(), where any non-null return value stops or
     * skips the worker loop. This listener must always return null.
     */
    public function looping(Looping $event): null
    {
        $this->guard(function () use ($event): void {
            if ($this->startedAt === null) {
                $this->begin((string) $event->connectionName, (string) $event->queue);
                $this->beat('starting');

                return;
            }

            $interval = max(1, (int) config('watchtower-agent.worker_heartbeat_seconds', 30));

            if ($this->lastBeatAt !== null && $this->now() - $this->lastBeatAt < $interval) {
                return;
            }

            $this->beat('alive');
        });

        return null;
    }

    public function jobFinished(JobProcessed|JobExceptionOccurred $event): void
    {
        $this->jobsProcessed++;
    }

    public function stopping(WorkerStopping $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->startedAt === null) {
                $this->begin((string) ($event->connectionName ?? ''), (string) ($event->queue ?? ''));
            }

            $connection = $event->connectionName ?? null;
            $queue = $event->queue ?? null;

            if (is_string($connection) && $connection !== '') {
                $this->connection = $connection;
            }

            if (is_string($queue) && $queue !== '') {
                $this->queue = $queue;
            }

            $jobs = $event->jobsProcessed ?? null;
            $memory = $event->memoryUsage ?? null;

            $this->beat(
                'stopping',
                jobsProcessed: is_numeric($jobs) ? (int) $jobs : $this->jobsProcessed,
                memory: is_numeric($memory) ? (int) $memory : null,
                reason: $this->reason($event),
            );
        });
    }

    public function workerId(): ?string
    {
        if ($this->startedAt === null) {
            return null;
        }

        return $this->host().':'.getmypid().':'.(int) $this->startedAt;
    }

    private function begin(string $connection, string $queue): void
    {
        $this->startedAt = $this->now();
        $this->lastBeatAt = null;
        $this->connection = $connection;
        $this->queue = $queue;
        $this->jobsProcessed = 0;
    }

    private function beat(string $status, ?int $jobsProcessed = null, ?int $memory = null, ?string $reason = null): void
    {
        $this->lastBeatAt = $this->now();

        $this->recorder->send('wt-worker', [
            'worker_id' => (string) $this->workerId(),
            'host' => $this->host(),
            'pid' => (int) getmypid(),
            'connection' => $this->connection,
            'queue' => $this->queue,
            'status' => $status,
            'started_at' => (float) $this->startedAt,
            'jobs_processed' => $jobsProcessed ?? $this->jobsProcessed,
            'memory' => $memory ?? memory_get_usage(true),
            'reason' => $reason,
        ]);
    }

    private function reason(WorkerStopping $event): ?string
    {
        $reason = $event->reason ?? null;

        return match (true) {
            $reason instanceof BackedEnum => (string) $reason->value,
            $reason instanceof UnitEnum => $reason->name,
            is_scalar($reason) => (string) $reason,
            default => is_numeric($event->status ?? null) ? 'status:'.$event->status : null,
        };
    }

    private function host(): string
    {
        return (string) gethostname();
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }

    private function guard(callable $callback): void
    {
        if (! WatchtowerAgent::feature('workers')) {
            return;
        }

        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
