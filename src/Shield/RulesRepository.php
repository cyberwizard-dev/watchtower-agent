<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Shield rules in the app cache. The last good copy is kept forever and used past its ttl when
 * Watch Tower can't be reached (fail open: no copy at all means no rules). Requests never wait
 * for Watch Tower: a missing or stale copy is refreshed after the response is sent.
 */
class RulesRepository
{
    public const KEY = 'watchtower-agent:shield:rules';

    public const LOCK_KEY = 'watchtower-agent:shield:refreshing';

    /**
     * Seconds between two lazy refresh attempts from requests (the scheduled sync ignores it).
     */
    public const LOCK_SECONDS = 15;

    private bool $refreshScheduled = false;

    public function __construct(
        private CacheFactory $cache,
        private Config $config,
        private RulesClient $client,
        private Application $app,
    ) {}

    /**
     * The cached rules, fresh or not. Never contacts Watch Tower.
     */
    public function current(): ?ShieldRules
    {
        $entry = $this->entry();

        return $entry === null ? null : ShieldRules::fromArray($entry['rules']);
    }

    /**
     * True when there is no copy or the copy is older than its ttl.
     */
    public function stale(): bool
    {
        $entry = $this->entry();

        if ($entry === null) {
            return true;
        }

        $ttl = max(5, (int) ($entry['rules']['ttl_seconds'] ?? 60));

        return Carbon::now()->getTimestamp() >= $entry['fetched_at'] + $ttl;
    }

    /**
     * Pull the rules now. "updated", "not_modified", "failed" or "unconfigured". A failure keeps
     * the last good copy.
     */
    public function refresh(): string
    {
        if (! $this->client->configured()) {
            return 'unconfigured';
        }

        $entry = $this->entry();

        try {
            $rules = $this->client->fetch($entry === null ? null : (string) ($entry['rules']['version'] ?? ''));
        } catch (Throwable) {
            return 'failed';
        }

        // 304 without a copy to keep can't happen unless the cache was flushed in between.
        if ($rules === null && $entry === null) {
            return 'failed';
        }

        $this->store()->forever(self::KEY, [
            'rules' => $rules === null ? $entry['rules'] : $rules->toArray(),
            'fetched_at' => Carbon::now()->getTimestamp(),
        ]);

        return $rules === null ? 'not_modified' : 'updated';
    }

    /**
     * Refresh after the response has been sent, at most once per request and, across
     * processes, once per LOCK_SECONDS.
     */
    public function refreshAfterResponse(): void
    {
        if ($this->refreshScheduled || ! $this->client->configured()) {
            return;
        }

        $this->refreshScheduled = true;

        $this->app->terminating(function (): void {
            $this->refreshScheduled = false;

            Guard::safely(function (): void {
                if ($this->store()->add(self::LOCK_KEY, 1, self::LOCK_SECONDS)) {
                    $this->refresh();
                }
            });
        });
    }

    /**
     * The configured store without an event dispatcher, so Nightwatch's cache sensor never
     * sees the agent's own bookkeeping.
     */
    public function store(): CacheContract
    {
        $store = $this->cache->store($this->config->get('watchtower-agent.shield.cache_store'));

        return new CacheRepository($store->getStore());
    }

    /**
     * Seconds since the cached copy was fetched, or null without a copy.
     */
    public function age(): ?int
    {
        $entry = $this->entry();

        return $entry === null ? null : max(0, Carbon::now()->getTimestamp() - $entry['fetched_at']);
    }

    /**
     * @return array{rules: array<string, mixed>, fetched_at: int}|null
     */
    private function entry(): ?array
    {
        $entry = $this->store()->get(self::KEY);

        if (! is_array($entry) || ! is_array($entry['rules'] ?? null) || ! is_int($entry['fetched_at'] ?? null)) {
            return null;
        }

        return $entry;
    }
}
