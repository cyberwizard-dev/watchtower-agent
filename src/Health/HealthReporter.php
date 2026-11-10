<?php

namespace Cyberwizard\WatchtowerAgent\Health;

use Closure;
use Throwable;

/**
 * Runs the built-in and registered checks and turns them into the wt-health "checks" list.
 */
class HealthReporter
{
    public function __construct(
        private BuiltInChecks $builtIn,
        private HealthCheckRegistry $registry,
    ) {}

    /**
     * @return list<array{name: string, status: string, value: ?float, unit: ?string, message: string, duration_ms: ?int}>
     */
    public function run(): array
    {
        $results = [];

        foreach ([...$this->builtIn->all(), ...$this->registry->all()] as $name => $check) {
            $results[] = $this->runCheck((string) $name, $check);
        }

        return $results;
    }

    /**
     * @return array{name: string, status: string, value: ?float, unit: ?string, message: string, duration_ms: ?int}
     */
    public function runCheck(string $name, Closure $check): array
    {
        $started = hrtime(true);

        try {
            $result = $check();
        } catch (Throwable $e) {
            $result = CheckResult::unhealthy(mb_substr($e->getMessage() ?: $e::class, 0, 1000));
        }

        $result = match (true) {
            $result instanceof CheckResult => $result,
            $result === true => CheckResult::healthy(),
            $result === false => CheckResult::unhealthy('Check failed'),
            default => CheckResult::unhealthy('Check did not return a CheckResult'),
        };

        return $result->toRecord($name, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
