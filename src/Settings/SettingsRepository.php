<?php

namespace Cyberwizard\WatchtowerAgent\Settings;

use Cyberwizard\WatchtowerAgent\Transport;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Settings from Watch Tower in the app cache. The last good copy is kept forever; a failed
 * pull never clears it. Every process applies the sampling part to Nightwatch, reading the
 * cache at most once per MEMO_SECONDS.
 */
class SettingsRepository
{
    public const KEY = 'watchtower-agent:settings:v1';

    public const MEMO_SECONDS = 60;

    /** Nightwatch sampling keys Watch Tower may set. */
    public const SAMPLING_KEYS = ['requests', 'commands', 'exceptions', 'scheduled_tasks'];

    /** @var array<string, float|null>|null */
    private static ?array $memo = null;

    private static ?int $memoAt = null;

    public function __construct(
        private CacheFactory $cache,
        private Config $config,
        private SettingsClient $client,
        private Transport $transport,
    ) {}

    /**
     * Pull the settings now: "updated", "not_modified", "failed" or "unconfigured".
     * A failure keeps the last good copy.
     */
    public function refresh(): string
    {
        if (! $this->client->configured()) {
            return 'unconfigured';
        }

        $entry = $this->entry();

        try {
            $settings = $this->client->fetch($entry === null ? null : (string) ($entry['settings']['version'] ?? ''));
        } catch (Throwable) {
            return 'failed';
        }

        if ($settings === null && $entry === null) {
            return 'failed';
        }

        $this->store()->forever(self::KEY, [
            'settings' => $settings ?? $entry['settings'],
            'fetched_at' => Carbon::now()->getTimestamp(),
        ]);

        self::forgetMemo();

        return $settings === null ? 'not_modified' : 'updated';
    }

    /**
     * The cached settings, or null without a copy. Never contacts Watch Tower.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        return $this->entry()['settings'] ?? null;
    }

    /**
     * Rates Watch Tower set (0..1), null for each one left to the app.
     *
     * @return array<string, float|null>
     */
    public function sampling(): array
    {
        $sampling = $this->current()['sampling'] ?? [];
        $rates = [];

        foreach (self::SAMPLING_KEYS as $key) {
            $value = is_array($sampling) ? ($sampling[$key] ?? null) : null;
            $rates[$key] = is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : null;
        }

        return $rates;
    }

    /**
     * Apply the synced sample rates to Nightwatch in this process.
     */
    public function apply(): void
    {
        $now = Carbon::now()->getTimestamp();

        if (self::$memo === null || self::$memoAt === null || $now - self::$memoAt >= self::MEMO_SECONDS) {
            self::$memo = $this->sampling();
            self::$memoAt = $now;
        }

        $this->transport->applySampling(self::$memo);
    }

    public static function forgetMemo(): void
    {
        self::$memo = null;
        self::$memoAt = null;
    }

    /**
     * The configured store without an event dispatcher, so Nightwatch's cache sensor never
     * sees the agent's own bookkeeping.
     */
    public function store(): CacheContract
    {
        $store = $this->cache->store($this->config->get('watchtower-agent.settings.cache_store'));

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
     * @return array{settings: array<string, mixed>, fetched_at: int}|null
     */
    private function entry(): ?array
    {
        $entry = $this->store()->get(self::KEY);

        if (! is_array($entry) || ! is_array($entry['settings'] ?? null) || ! is_int($entry['fetched_at'] ?? null)) {
            return null;
        }

        return $entry;
    }
}
