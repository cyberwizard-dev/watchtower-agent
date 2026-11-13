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

        $request = $request->withHeaders(['X-Watchtower-App-Sampling' => $this->appSampling()]);

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

    /**
     * The app's own sample rates (its NIGHTWATCH_*_SAMPLE_RATE values and the browser
     * script's data-sample), so Watch Tower can show what is in effect where it sets nothing.
     */
    private function appSampling(): string
    {
        $rates = [];

        foreach (['requests', 'commands', 'exceptions', 'scheduled_tasks'] as $key) {
            $value = $this->config->get("nightwatch.sampling.{$key}");
            $rates[$key] = is_numeric($value) ? round(max(0.0, min(1.0, (float) $value)), 4) : null;
        }

        $browser = $this->config->get('watchtower-agent.browser.sample');
        $rates['browser'] = is_numeric($browser) ? round(max(0.0, min(1.0, (float) $browser)), 4) : null;

        return (string) json_encode($rates);
    }
}
