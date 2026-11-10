<?php

namespace Cyberwizard\WatchtowerAgent\Queue;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Throwable;

/**
 * Real queue depth per connection+queue. A measure the driver can't provide is null.
 */
class QueueSnapshotter
{
    public function __construct(
        private Container $container,
        private QueueTargets $targets,
    ) {}

    /**
     * @return array{queues: list<array{connection: string, queue: string, size: ?int, pending: ?int, delayed: ?int, reserved: ?int, oldest_pending_at: ?float}>, failed_jobs: ?int}
     */
    public function snapshot(): array
    {
        $queues = [];

        foreach ($this->targets->all() as $target) {
            $queues[] = $this->measure($target['connection'], $target['queue']);
        }

        return ['queues' => $queues, 'failed_jobs' => $this->failedJobs()];
    }

    /**
     * @return array{connection: string, queue: string, size: ?int, pending: ?int, delayed: ?int, reserved: ?int, oldest_pending_at: ?float}
     */
    public function measure(string $connection, string $queue): array
    {
        $measure = [
            'connection' => $connection,
            'queue' => $queue,
            'size' => null,
            'pending' => null,
            'delayed' => null,
            'reserved' => null,
            'oldest_pending_at' => null,
        ];

        try {
            $driver = $this->container->make(QueueFactory::class)->connection($connection);
        } catch (Throwable) {
            return $measure;
        }

        $measure['size'] = $this->int($driver, 'size', $queue);
        $measure['pending'] = $this->int($driver, 'pendingSize', $queue);
        $measure['delayed'] = $this->int($driver, 'delayedSize', $queue);
        $measure['reserved'] = $this->int($driver, 'reservedSize', $queue);

        $oldest = $this->call($driver, 'creationTimeOfOldestPendingJob', $queue);
        $measure['oldest_pending_at'] = is_numeric($oldest) ? (float) $oldest : null;

        return $measure;
    }

    public function failedJobs(): ?int
    {
        try {
            $failer = $this->container->make('queue.failer');

            return $failer instanceof CountableFailedJobProvider ? (int) $failer->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function int(object $driver, string $method, string $queue): ?int
    {
        $value = $this->call($driver, $method, $queue);

        return is_numeric($value) ? (int) $value : null;
    }

    private function call(object $driver, string $method, string $queue): mixed
    {
        if (! method_exists($driver, $method)) {
            return null;
        }

        try {
            return $driver->{$method}($queue);
        } catch (Throwable) {
            return null;
        }
    }
}
