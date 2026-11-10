<?php

namespace Cyberwizard\WatchtowerAgent\Actions;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Verifies and runs pulled actions, then reports each result over HTTP and as wt-action-result.
 */
class ActionRunner
{
    public function __construct(
        private Container $container,
        private ActionVerifier $verifier,
        private ActionClient $client,
        private Recorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array{action_id: string, status: string, message: string}|null null when nothing is reported
     */
    public function handle(array $action): ?array
    {
        $verdict = $this->verifier->verify($action, time());
        $id = is_scalar($action['id'] ?? null) ? (string) $action['id'] : '';

        if (! $verdict->accepted) {
            return $verdict->report && $id !== '' ? $this->finish($id, 'rejected', $verdict->reason) : null;
        }

        try {
            [$status, $message] = match ($action['type']) {
                'retry_failed_job' => $this->retryFailedJob($action['payload']),
            };
        } catch (Throwable $e) {
            [$status, $message] = ['failed', mb_substr($e->getMessage() ?: $e::class, 0, 1000)];
        }

        return $this->finish($id, $status, $message);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private function retryFailedJob(array $payload): array
    {
        $failedJobId = $payload['failed_job_id'] ?? null;

        if (! is_scalar($failedJobId) || (string) $failedJobId === '') {
            return ['failed', 'The action has no failed_job_id'];
        }

        $failedJobId = (string) $failedJobId;

        if ($this->container->make('queue.failer')->find($failedJobId) === null) {
            return ['failed', "Failed job [{$failedJobId}] was not found"];
        }

        $kernel = $this->container->make(Kernel::class);
        $exitCode = $kernel->call('queue:retry', ['id' => [$failedJobId]]);
        $output = trim($kernel->output());

        if ($exitCode !== 0) {
            return ['failed', $output !== '' ? mb_substr($output, 0, 1000) : "queue:retry exited with code {$exitCode}"];
        }

        return ['done', "Failed job [{$failedJobId}] was pushed back onto the queue"];
    }

    /**
     * @return array{action_id: string, status: string, message: string}
     */
    private function finish(string $id, string $status, string $message): array
    {
        $result = ['action_id' => $id, 'status' => $status, 'message' => $message];

        try {
            $this->client->report($id, $status, $message);
        } catch (Throwable $e) {
            report($e);
        }

        $this->recorder->send('wt-action-result', $result);

        return $result;
    }
}
