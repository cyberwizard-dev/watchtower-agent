<?php

namespace Cyberwizard\WatchtowerAgent\Queue;

use Illuminate\Contracts\Container\Container;
use Throwable;

final class HorizonSnapshotter
{
    public function __construct(private Container $container) {}

    public function snapshot(): ?array
    {
        $contracts = [
            'jobs' => 'Laravel\\Horizon\\Contracts\\JobRepository',
            'supervisors' => 'Laravel\\Horizon\\Contracts\\SupervisorRepository',
            'masters' => 'Laravel\\Horizon\\Contracts\\MasterSupervisorRepository',
        ];

        foreach ($contracts as $contract) {
            if (! interface_exists($contract) || ! $this->container->bound($contract)) {
                return null;
            }
        }

        try {
            $jobs = $this->container->make($contracts['jobs']);
            $supervisorRepository = $this->container->make($contracts['supervisors']);
            $masterRepository = $this->container->make($contracts['masters']);
            $supervisors = $supervisorRepository->all();
            $masters = $masterRepository->all();
            $normalizedSupervisors = [];
            $workers = 0;

            foreach ($supervisors as $supervisor) {
                $processes = is_array($supervisor->processes ?? null) ? $supervisor->processes : [];
                $workerCount = array_sum(array_map('intval', $processes));
                $workers += $workerCount;
                $normalizedSupervisors[] = [
                    'name' => $this->string($supervisor->name ?? null),
                    'master' => $this->string($supervisor->master ?? null),
                    'pid' => $this->integer($supervisor->pid ?? null),
                    'status' => $this->string($supervisor->status ?? null),
                    'workers' => $workerCount,
                    'processes' => $this->limitedMap($processes),
                    'connection' => $this->string($supervisor->options['connection'] ?? null),
                    'queues' => $this->stringList($supervisor->options['queue'] ?? null),
                ];
            }

            $masterRunning = collect($masters)->contains(fn ($master) => ($master->status ?? null) === 'running');

            return [
                'status' => $masterRunning ? 'running' : (count($masters) > 0 ? 'paused' : 'inactive'),
                'masters' => count($masters),
                'supervisors' => count($normalizedSupervisors),
                'workers' => $workers,
                'recent' => $this->count($jobs, 'countRecent'),
                'pending' => $this->count($jobs, 'countPending'),
                'completed' => $this->count($jobs, 'countCompleted'),
                'failed' => $this->count($jobs, 'countFailed'),
                'recently_failed' => $this->count($jobs, 'countRecentlyFailed'),
                'silenced' => $this->count($jobs, 'countSilenced'),
                'supervisor_details' => array_slice($normalizedSupervisors, 0, 100),
                'master_details' => array_slice(array_map(fn ($master) => [
                    'name' => $this->string($master->name ?? null),
                    'pid' => $this->integer($master->pid ?? null),
                    'status' => $this->string($master->status ?? null),
                ], $masters), 0, 50),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function count(object $repository, string $method): ?int
    {
        try {
            $value = $repository->{$method}();

            return is_numeric($value) ? max(0, (int) $value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 190) : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function limitedMap(array $values): array
    {
        $result = [];
        foreach (array_slice($values, 0, 50, true) as $key => $value) {
            $result[mb_substr((string) $key, 0, 190)] = max(0, (int) $value);
        }

        return $result;
    }

    private function stringList(mixed $value): array
    {
        return is_string($value) ? array_slice(array_filter(array_map('trim', explode(',', $value))), 0, 50) : [];
    }
}
