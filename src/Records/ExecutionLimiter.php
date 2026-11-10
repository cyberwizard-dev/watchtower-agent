<?php

namespace Cyberwizard\WatchtowerAgent\Records;

/**
 * Per-execution record counts. Nightwatch keeps at most 500 records per execution in a ring
 * buffer and drops the oldest, so enrichment records are capped per request, job, command or
 * scheduled task. Counts reset whenever Nightwatch's execution id changes.
 */
class ExecutionLimiter
{
    private ?string $execution = null;

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * Count one more record in the bucket; false once the bucket is past $max (0 = no cap).
     */
    public function allow(string $bucket, string $execution, int $max): bool
    {
        $this->sync($execution);

        $this->counts[$bucket] = ($this->counts[$bucket] ?? 0) + 1;

        return $max <= 0 || $this->counts[$bucket] <= $max;
    }

    public function count(string $bucket, string $execution): int
    {
        $this->sync($execution);

        return $this->counts[$bucket] ?? 0;
    }

    public function reset(): void
    {
        $this->execution = null;
        $this->counts = [];
    }

    private function sync(string $execution): void
    {
        if ($this->execution !== $execution) {
            $this->execution = $execution;
            $this->counts = [];
        }
    }
}
