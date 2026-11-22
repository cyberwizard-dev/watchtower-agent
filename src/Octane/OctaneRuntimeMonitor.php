<?php

namespace Cyberwizard\WatchtowerAgent\Octane;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Throwable;

final class OctaneRuntimeMonitor
{
    private ?float $startedAt = null;

    private ?float $requestStartedAt = null;

    private ?float $lastReportedAt = null;

    private ?int $baselineMemory = null;

    private ?int $lastRequestDuration = null;

    private int $requestsHandled = 0;

    private int $requestsSinceReport = 0;

    private int $peakMemory = 0;

    public function __construct(private readonly Recorder $recorder) {}

    public function workerStarted(object $event): void
    {
        $this->guard(function () use ($event): void {
            $this->startedAt = microtime(true);
            $this->lastReportedAt = $this->startedAt;
            $this->baselineMemory = memory_get_usage(false);
            $this->peakMemory = memory_get_peak_usage(false);
            $this->requestsHandled = 0;
            $this->requestsSinceReport = 0;
            $this->report('started', $event);
        });
    }

    public function requestReceived(object $event): void
    {
        $this->guard(function (): void {
            if ($this->startedAt === null) {
                $this->startedAt = microtime(true);
                $this->lastReportedAt = $this->startedAt;
                $this->baselineMemory = memory_get_usage(false);
            }

            $this->requestStartedAt = microtime(true);
        });
    }

    public function requestHandled(object $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->startedAt === null) {
                $this->startedAt = microtime(true);
                $this->lastReportedAt = $this->startedAt;
                $this->baselineMemory = memory_get_usage(false);
            }

            $now = microtime(true);
            $this->requestsHandled++;
            $this->requestsSinceReport++;
            $this->lastRequestDuration = $this->requestStartedAt === null
                ? null
                : (int) round(($now - $this->requestStartedAt) * 1000);
            $this->requestStartedAt = null;
            $this->peakMemory = max($this->peakMemory, memory_get_peak_usage(false));
            $interval = max(10, (int) config('watchtower-agent.octane.snapshot_seconds', 30));

            if ($this->lastReportedAt === null || $now - $this->lastReportedAt >= $interval) {
                $this->report('running', $event);
                $this->lastReportedAt = $now;
                $this->requestsSinceReport = 0;
            }
        });
    }

    public function workerStopping(object $event): void
    {
        $this->guard(fn () => $this->report('stopped', $event));
    }

    public function workerError(object $event): void
    {
        $this->guard(function () use ($event): void {
            $exception = $event->exception ?? null;
            $this->report('error', $event, $exception instanceof Throwable ? get_class($exception) : null);
        });
    }

    private function report(string $status, object $event, ?string $errorClass = null): void
    {
        $now = microtime(true);
        $memory = memory_get_usage(false);
        $startedAt = $this->startedAt ?? $now;
        $workerId = (string) gethostname().':'.(int) getmypid().':'.(int) $startedAt;

        $this->recorder->send('wt-octane', [
            'worker_id' => $workerId,
            'host' => (string) gethostname(),
            'pid' => (int) getmypid(),
            'status' => $status,
            'requests' => $this->requestsHandled,
            'requests_since_report' => $this->requestsSinceReport,
            'memory_bytes' => $memory,
            'peak_memory_bytes' => max($this->peakMemory, memory_get_peak_usage(false)),
            'memory_growth_bytes' => $this->baselineMemory === null ? null : max(0, $memory - $this->baselineMemory),
            'last_request_duration_ms' => $this->lastRequestDuration,
            'uptime_seconds' => max(0, (int) ($now - $startedAt)),
            'error_class' => $errorClass,
            'started_at' => $startedAt,
        ]);
    }

    private function guard(callable $callback): void
    {
        if (! WatchtowerAgent::feature('octane')) {
            return;
        }

        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
