<?php

namespace Cyberwizard\WatchtowerAgent\Health;

use Closure;
use Cyberwizard\WatchtowerAgent\Queue\QueueSnapshotter;
use Cyberwizard\WatchtowerAgent\Queue\QueueTargets;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

/**
 * The checks the agent runs without any application code: database, Redis, cache, disk,
 * queue reachability and failed job count.
 */
class BuiltInChecks
{
    public function __construct(
        private Container $container,
        private Repository $config,
        private QueueTargets $queues,
        private QueueSnapshotter $snapshotter,
    ) {}

    /**
     * @return array<string, Closure(): CheckResult>
     */
    public function all(): array
    {
        $checks = [];

        foreach ($this->databaseConnections() as $connection) {
            $checks["database:{$connection}"] = fn (): CheckResult => $this->database($connection);
        }

        foreach ($this->redisConnections() as $connection) {
            $checks["redis:{$connection}"] = fn (): CheckResult => $this->redis($connection);
        }

        if ($this->config->get('watchtower-agent.health.cache', true)) {
            $store = $this->config->get('watchtower-agent.health.cache_store') ?? $this->config->get('cache.default');
            $checks["cache:{$store}"] = fn (): CheckResult => $this->cache($store);
        }

        foreach ((array) $this->config->get('watchtower-agent.health.disk_paths', []) as $path) {
            $checks["disk:{$path}"] = fn (): CheckResult => $this->disk((string) $path);
        }

        if ($this->config->get('watchtower-agent.health.queues', true)) {
            foreach ($this->queues->all() as $target) {
                $checks["queue:{$target['connection']}/{$target['queue']}"] = fn (): CheckResult => $this->queue($target['connection'], $target['queue']);
            }
        }

        if ($this->config->get('watchtower-agent.health.failed_jobs', true)) {
            $checks['failed-jobs'] = fn (): CheckResult => $this->failedJobs();
        }

        return $checks;
    }

    public function database(string $connection): CheckResult
    {
        $started = hrtime(true);
        $this->container->make('db')->connection($connection)->select('select 1');

        return $this->timed('Database responded', $started);
    }

    public function redis(string $connection): CheckResult
    {
        $started = hrtime(true);
        $this->container->make('redis')->connection($connection)->ping();

        return $this->timed('Redis responded to PING', $started);
    }

    public function cache(?string $store): CheckResult
    {
        $repository = $this->container->make('cache')->store($store);
        $key = 'watchtower-agent:health:'.Str::random(16);
        $value = Str::random(16);

        $started = hrtime(true);

        $repository->put($key, $value, 60);
        $read = $repository->get($key);
        $repository->forget($key);

        if ($read !== $value) {
            return CheckResult::unhealthy('Cache returned a different value than was written');
        }

        return $this->timed('Cache round-trip succeeded', $started);
    }

    public function disk(string $path): CheckResult
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return CheckResult::unhealthy("Disk space for {$path} could not be read");
        }

        $percent = round($free / $total * 100, 2);
        $message = sprintf('%s%% free (%s of %s)', $percent, $this->bytes($free), $this->bytes($total));

        return match (true) {
            $percent < (float) $this->config->get('watchtower-agent.health.disk_unhealthy_below_percent', 5) => CheckResult::unhealthy($message, $percent, '%'),
            $percent < (float) $this->config->get('watchtower-agent.health.disk_degraded_below_percent', 15) => CheckResult::degraded($message, $percent, '%'),
            default => CheckResult::healthy($message, $percent, '%'),
        };
    }

    public function queue(string $connection, string $queue): CheckResult
    {
        $driver = $this->container->make('queue')->connection($connection);
        $size = $driver->size($queue);

        return CheckResult::healthy("{$size} job(s) waiting", (int) $size, 'jobs');
    }

    public function failedJobs(): CheckResult
    {
        $count = $this->snapshotter->failedJobs();

        if ($count === null) {
            return CheckResult::healthy('Failed job store cannot be counted');
        }

        $threshold = $this->config->get('watchtower-agent.health.failed_jobs_degraded_above');

        if (is_numeric($threshold) && $count > (int) $threshold) {
            return CheckResult::degraded("{$count} failed job(s)", $count, 'jobs');
        }

        return CheckResult::healthy("{$count} failed job(s)", $count, 'jobs');
    }

    /**
     * @return list<string>
     */
    private function databaseConnections(): array
    {
        $configured = $this->config->get('watchtower-agent.health.database_connections');

        if (is_array($configured)) {
            return array_values(array_map('strval', $configured));
        }

        $default = $this->config->get('database.default');

        return is_string($default) && $default !== '' ? [$default] : [];
    }

    /**
     * @return list<string>
     */
    private function redisConnections(): array
    {
        $configured = $this->config->get('watchtower-agent.health.redis_connections');

        if (is_array($configured)) {
            return array_values(array_map('strval', $configured));
        }

        $usesRedis = $this->config->get('cache.stores.'.$this->config->get('cache.default').'.driver') === 'redis'
            || $this->config->get('queue.connections.'.$this->config->get('queue.default').'.driver') === 'redis'
            || $this->config->get('session.driver') === 'redis';

        return $usesRedis && $this->config->get('database.redis.default') !== null ? ['default'] : [];
    }

    private function timed(string $message, int $started): CheckResult
    {
        $milliseconds = round((hrtime(true) - $started) / 1_000_000, 2);
        $slow = (int) $this->config->get('watchtower-agent.health.slow_ms', 1000);

        return $slow > 0 && $milliseconds > $slow
            ? CheckResult::degraded("{$message} slowly ({$milliseconds} ms)", $milliseconds, 'ms')
            : CheckResult::healthy($message, $milliseconds, 'ms');
    }

    private function bytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 1).' '.$units[$index];
    }
}
