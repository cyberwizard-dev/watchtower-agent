<?php

namespace Cyberwizard\WatchtowerAgent\Actions;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;

/**
 * Decides whether a pulled action may run: signed with the environment's action secret, not
 * expired, an allow-listed type, and never seen before.
 */
class ActionVerifier
{
    /**
     * @var list<string>
     */
    public const ALLOWED_TYPES = ['retry_failed_job'];

    public const REPLAY_KEY = 'watchtower-agent:action:';

    public function __construct(
        private Repository $config,
        private CacheFactory $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     */
    public function verify(array $action, int $now): ActionVerdict
    {
        $id = $action['id'] ?? null;
        $type = $action['type'] ?? null;
        $payload = $action['payload'] ?? null;
        $issuedAt = $action['issued_at'] ?? null;
        $expiresAt = $action['expires_at'] ?? null;
        $signature = $action['signature'] ?? null;

        if (! is_scalar($id) || (string) $id === '' || ! is_string($type) || ! is_array($payload)
            || ! is_numeric($issuedAt) || ! is_numeric($expiresAt) || ! is_string($signature)) {
            return ActionVerdict::reject('Malformed action', report: is_scalar($id) && (string) $id !== '');
        }

        $secret = (string) $this->config->get('watchtower-agent.actions.secret', '');

        if ($secret === '') {
            return ActionVerdict::reject('No action secret is configured');
        }

        $expected = ActionSignature::sign((string) $id, $type, $payload, (int) $issuedAt, (int) $expiresAt, $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            return ActionVerdict::reject('Invalid signature');
        }

        if ((int) $expiresAt <= $now) {
            return ActionVerdict::reject('Action expired');
        }

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return ActionVerdict::reject("Action type [{$type}] is not allowed");
        }

        // Claimed atomically so two pulls can never both run the same action.
        $ttl = max(60, (int) $expiresAt - $now + 86400);

        if (! $this->store()->add(self::REPLAY_KEY.$id, $now, $ttl)) {
            return ActionVerdict::reject('Action already processed', report: false);
        }

        return ActionVerdict::accept();
    }

    private function store(): Cache
    {
        return $this->cache->store($this->config->get('watchtower-agent.actions.cache_store'));
    }
}
