<?php

namespace Cyberwizard\WatchtowerAgent\Records;

use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;

/**
 * At most one record per key per window, so a client hammering the app (a blocked IP, a
 * credential stuffing run) costs one immediate write per window instead of one per request.
 * The window is shared by every process through the cache (PHP-FPM keeps nothing between
 * requests) and remembered in this process too (Octane, queue workers), which also covers a
 * failing cache store.
 */
class RecordThrottle
{
    private const PREFIX = 'watchtower-agent:throttle:';

    private const MAX_KEYS = 1000;

    /** @var array<string, int> cache key => unix time the window ends, in this process */
    private array $windows = [];

    public function __construct(private CacheFactory $cache) {}

    /**
     * True when a record for $key may be sent now; false while the key's window is open.
     * $seconds <= 0 disables the throttle. $store is a cache store name (null = default).
     */
    public function allow(string $key, int $seconds, ?string $store = null): bool
    {
        if ($seconds <= 0) {
            return true;
        }

        $cacheKey = self::PREFIX.hash('xxh128', $key);
        $now = Carbon::now()->getTimestamp();

        if (($this->windows[$cacheKey] ?? 0) > $now) {
            return false;
        }

        $this->remember($cacheKey, $now + $seconds, $now);

        return (bool) Guard::safely(fn (): bool => $this->store($store)->add($cacheKey, 1, $seconds), true);
    }

    public function reset(): void
    {
        $this->windows = [];
    }

    private function remember(string $cacheKey, int $until, int $now): void
    {
        if (count($this->windows) >= self::MAX_KEYS) {
            $this->windows = array_filter($this->windows, static fn (int $end): bool => $end > $now);

            if (count($this->windows) >= self::MAX_KEYS) {
                $this->windows = [];
            }
        }

        $this->windows[$cacheKey] = $until;
    }

    /**
     * The store without an event dispatcher, so Nightwatch's cache sensor never sees the
     * agent's own bookkeeping.
     */
    private function store(?string $store): CacheRepository
    {
        return new CacheRepository($this->cache->store($store)->getStore());
    }
}
