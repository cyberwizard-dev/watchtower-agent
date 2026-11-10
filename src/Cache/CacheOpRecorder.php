<?php

namespace Cyberwizard\WatchtowerAgent\Cache;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * wt-cache-op: the cache operations Nightwatch does not report: store flushes (and their
 * failures), lock flushes, failover-store switches and, opt-in, lock acquire/block/release.
 * Increments, decrements and expirations fire no Laravel event and are not observable.
 */
class CacheOpRecorder
{
    /** @var array<string, int> */
    private array $started = [];

    public function __construct(private Container $container) {}

    /**
     * Opt-in lock instrumentation: a "lock" / "restoreLock" macro on the cache Repository
     * (Repository forwards unknown calls to its store, so this is the only seam). Skipped when
     * the app already defines such a macro.
     */
    public static function installLockMacros(): void
    {
        foreach (['lock', 'restoreLock'] as $method) {
            if (Repository::hasMacro($method)) {
                continue;
            }

            Repository::macro($method, function (...$arguments) use ($method) {
                /** @var Repository $this */
                $lock = $this->getStore()->{$method}(...$arguments);

                return app(CacheOpRecorder::class)->wrapLock(
                    $lock,
                    (string) ($this->getName() ?? ''),
                    (string) ($arguments[0] ?? ''),
                    $method === 'lock' ? (int) ($arguments[1] ?? 0) : 0,
                );
            });
        }
    }

    /**
     * Cache::flush() goes straight to the store through Repository::__call() and fires no
     * event; Repository::clear() does the same store flush and fires CacheFlushing /
     * CacheFlushed / CacheFlushFailed. A "flush" macro routes flush() to clear() so it is
     * observable (and so is `artisan cache:clear`). Return value and behaviour are unchanged.
     */
    public static function installFlushMacro(): void
    {
        if (method_exists(Repository::class, 'flush')
            || Repository::hasMacro('flush')
            || ! class_exists('Illuminate\Cache\Events\CacheFlushed')) {
            return;
        }

        Repository::macro('flush', function (): bool {
            /** @var Repository $this */
            return $this->clear();
        });
    }

    public function wrapLock(mixed $lock, string $store, string $key, int $seconds): mixed
    {
        if (! $lock instanceof Lock || $lock instanceof InstrumentedLock || ! $this->enabled() || ! config('watchtower-agent.cache_ops.locks', false)) {
            return $lock;
        }

        return new InstrumentedLock($lock, $this, $store, $key, $seconds);
    }

    public function starting(object $event): void
    {
        $this->started[$event::class.'|'.($event->storeName ?? '')] = hrtime(true);
    }

    public function flushed(object $event): void
    {
        $this->record('flush', $event->storeName ?? null, null, $this->elapsed('Illuminate\Cache\Events\CacheFlushing', $event->storeName ?? null));
    }

    public function flushFailed(object $event): void
    {
        $this->record('flush-failure', $event->storeName ?? null, null, $this->elapsed('Illuminate\Cache\Events\CacheFlushing', $event->storeName ?? null), null, 'The cache store could not be flushed');
    }

    public function locksFlushed(object $event): void
    {
        $this->record('locks-flush', $event->storeName ?? null, null, $this->elapsed('Illuminate\Cache\Events\CacheLocksFlushing', $event->storeName ?? null));
    }

    public function locksFlushFailed(object $event): void
    {
        $this->record('locks-flush', $event->storeName ?? null, null, $this->elapsed('Illuminate\Cache\Events\CacheLocksFlushing', $event->storeName ?? null), null, 'The cache locks could not be flushed');
    }

    public function failedOver(object $event): void
    {
        $exception = $event->exception ?? null;

        $this->record('failover', $event->storeName ?? null, null, null, null, $exception instanceof Throwable ? Redactor::exception($exception) : null);
    }

    public function lock(string $op, string $store, string $key, ?int $duration, ?int $ttl, ?string $error = null): void
    {
        $this->record($op, $store, $key, $duration, $ttl, $error);
    }

    /**
     * Mask a cache key: "masked" (default) replaces id-like segments with "*", "hidden"
     * drops the key, "full" keeps it.
     */
    public static function maskKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return match (config('watchtower-agent.cache_ops.keys', 'masked')) {
            'full' => $key,
            'hidden' => null,
            default => (string) preg_replace(
                [
                    '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
                    '/\b[0-9A-HJKMNP-TV-Z]{26}\b/',
                    '/[0-9a-f]{16,}/i',
                    '/[^\s:|\/]+@[^\s:|\/]+/',
                    '/\d+/',
                ],
                '*',
                $key,
            ),
        };
    }

    private function record(string $op, ?string $store, ?string $key, ?int $duration, ?int $ttl = null, ?string $error = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $store = (string) ($store ?? config('cache.default', ''));

        $this->container->make(Recorder::class)->inExecution('wt-cache-op', fn (): array => [
            'op' => $op,
            'store' => $store,
            'driver' => (string) config("cache.stores.{$store}.driver", ''),
            'key' => self::maskKey($key),
            'duration' => $duration,
            'ttl' => $ttl,
            'error' => $error,
        ], (int) config('watchtower-agent.cache_ops.max_per_execution', 100));
    }

    private function elapsed(string $startEvent, ?string $store): ?int
    {
        $key = $startEvent.'|'.($store ?? '');

        if (! isset($this->started[$key])) {
            return null;
        }

        $duration = (int) ((hrtime(true) - $this->started[$key]) / 1000);
        unset($this->started[$key]);

        return $duration;
    }

    private function enabled(): bool
    {
        return ! Guard::active() && WatchtowerAgent::feature('cache_ops');
    }
}
