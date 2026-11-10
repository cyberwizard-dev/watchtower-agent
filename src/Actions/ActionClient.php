<?php

namespace Cyberwizard\WatchtowerAgent\Actions;

use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Watch Tower's remote action API, authenticated with the environment token.
 */
class ActionClient
{
    public function __construct(
        private Http $http,
        private Repository $config,
    ) {}

    public function configured(): bool
    {
        return filled($this->config->get('watchtower-agent.actions.base_url'))
            && filled($this->config->get('watchtower-agent.actions.token'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pull(): array
    {
        $response = Guard::quietly(fn () => $this->request()->post($this->url('/api/agent-actions/pull')));

        if (! $response->successful()) {
            throw new RuntimeException("Watch Tower returned HTTP {$response->status()} for the action pull");
        }

        $actions = $response->json('actions');

        return is_array($actions) ? array_values(array_filter($actions, 'is_array')) : [];
    }

    public function report(string $id, string $status, string $message): bool
    {
        $response = Guard::quietly(fn () => $this->request()->post(
            $this->url('/api/agent-actions/'.rawurlencode($id).'/result'),
            ['status' => $status, 'message' => $message],
        ));

        return $response->successful();
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->withToken((string) $this->config->get('watchtower-agent.actions.token'))
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, (int) $this->config->get('watchtower-agent.actions.timeout', 10)))
            // The agent's own calls to Watch Tower never produce wt-http or a traceparent.
            ->withOptions([HttpRecorder::OPTION_IGNORE => true]);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config->get('watchtower-agent.actions.base_url'), '/').$path;
    }
}
