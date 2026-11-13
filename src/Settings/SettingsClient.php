<?php

namespace Cyberwizard\WatchtowerAgent\Settings;

use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * GET {base_url}/api/agent/v1/settings with the environment token.
 */
class SettingsClient
{
    public function __construct(
        private Http $http,
        private Repository $config,
    ) {}

    public function configured(): bool
    {
        return filled($this->config->get('watchtower-agent.settings.base_url'))
            && filled($this->config->get('watchtower-agent.settings.token'));
    }

    /**
     * Fetch the settings. Null means "not modified" (304 for the version sent in If-None-Match).
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException when Watch Tower is unreachable or answers with anything else
     */
    public function fetch(?string $version = null): ?array
    {
        $timeout = max(1, (int) $this->config->get('watchtower-agent.settings.timeout', 5));

        $request = $this->http
            ->withToken((string) $this->config->get('watchtower-agent.settings.token'))
            ->acceptJson()
            ->connectTimeout($timeout)
            ->timeout($timeout)
            ->withOptions([HttpRecorder::OPTION_IGNORE => true]);

        if ($version !== null && $version !== '') {
            $request = $request->withHeaders(['If-None-Match' => '"'.$version.'"']);
        }

        $url = rtrim((string) $this->config->get('watchtower-agent.settings.base_url'), '/').'/api/agent/v1/settings';
        $response = Guard::quietly(fn () => $request->get($url));

        if ($response->status() === 304) {
            return null;
        }

        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data)) {
            throw new RuntimeException("Watch Tower returned HTTP {$response->status()} for the agent settings");
        }

        return $data;
    }
}
