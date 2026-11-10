<?php

namespace Cyberwizard\WatchtowerAgent\Queue;

use Illuminate\Contracts\Config\Repository;

/**
 * The connection+queue pairs the agent measures.
 */
class QueueTargets
{
    public function __construct(private Repository $config) {}

    /**
     * @return list<array{connection: string, queue: string}>
     */
    public function all(): array
    {
        $configured = $this->config->get('watchtower-agent.queues');

        if (! is_array($configured)) {
            $connection = (string) $this->config->get('queue.default', 'sync');

            return [['connection' => $connection, 'queue' => $this->defaultQueue($connection)]];
        }

        $targets = [];

        foreach ($configured as $entry) {
            if (is_array($entry)) {
                $connection = (string) ($entry['connection'] ?? $this->config->get('queue.default'));
                $queue = isset($entry['queue']) ? (string) $entry['queue'] : $this->defaultQueue($connection);
            } else {
                $entry = (string) $entry;

                if (str_contains($entry, ':')) {
                    [$connection, $queue] = explode(':', $entry, 2);
                } else {
                    [$connection, $queue] = [$entry, $this->defaultQueue($entry)];
                }
            }

            $targets[$connection.':'.$queue] = ['connection' => $connection, 'queue' => $queue];
        }

        return array_values($targets);
    }

    private function defaultQueue(string $connection): string
    {
        return (string) ($this->config->get("queue.connections.{$connection}.queue") ?? 'default');
    }
}
