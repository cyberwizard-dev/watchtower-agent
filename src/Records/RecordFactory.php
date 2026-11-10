<?php

namespace Cyberwizard\WatchtowerAgent\Records;

use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Contracts\Config\Repository;

/**
 * Builds the common header every Watch Tower record carries (v, t, timestamp, deploy, server,
 * agent_version), mirroring the header Nightwatch's own sensors write.
 */
class RecordFactory
{
    public function __construct(private Repository $config) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function make(string $type, array $payload = []): array
    {
        return [
            'v' => 1,
            't' => $type,
            'timestamp' => microtime(true),
            'deploy' => (string) ($this->config->get('nightwatch.deployment') ?? ''),
            'server' => (string) ($this->config->get('nightwatch.server') ?? (string) gethostname()),
            'agent_version' => WatchtowerAgent::VERSION,
            ...$payload,
        ];
    }
}
