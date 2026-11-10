<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * GET {base_url}/api/shield/v1/rules with the environment token.
 */
class RulesClient
{
    public function __construct(
        private Http $http,
        private Repository $config,
    ) {}

    public function configured(): bool
    {
        return filled($this->config->get('watchtower-agent.shield.base_url'))
            && filled($this->config->get('watchtower-agent.shield.token'));
    }

    /**
     * Fetch the rules. Null means "not modified" (304 for the version sent in If-None-Match).
     *
     * @throws RuntimeException when Watch Tower is unreachable or answers with anything else
     */
    public function fetch(?string $version = null): ?ShieldRules
    {
        $timeout = max(1, (int) $this->config->get('watchtower-agent.shield.timeout', 2));

        $request = $this->http
            ->withToken((string) $this->config->get('watchtower-agent.shield.token'))
            ->acceptJson()
            ->connectTimeout($timeout)
            ->timeout($timeout)
            // The agent's own calls to Watch Tower never produce wt-http or a traceparent.
            ->withOptions([HttpRecorder::OPTION_IGNORE => true]);

        if ($version !== null && $version !== '') {
            $request = $request->withHeaders(['If-None-Match' => '"'.$version.'"']);
        }

        $url = rtrim((string) $this->config->get('watchtower-agent.shield.base_url'), '/').'/api/shield/v1/rules';
        $response = Guard::quietly(fn () => $request->get($url));

        if ($response->status() === 304) {
            return null;
        }

        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data)) {
            throw new RuntimeException("Watch Tower returned HTTP {$response->status()} for the Shield rules");
        }

        return ShieldRules::fromArray($data);
    }
}
