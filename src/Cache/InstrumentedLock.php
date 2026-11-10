<?php

namespace Cyberwizard\WatchtowerAgent\Cache;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Decorates a lock returned by Cache::lock() (opt-in, see cache_ops.locks) and reports
 * acquired / blocked / released. Semantics of get() and block() with a callback match
 * Illuminate\Cache\Lock exactly; anything else is forwarded to the real lock.
 */
class InstrumentedLock implements Lock
{
    public function __construct(
        private Lock $inner,
        private CacheOpRecorder $recorder,
        private string $store,
        private string $key,
        private int $seconds,
    ) {}

    public function inner(): Lock
    {
        return $this->inner;
    }

    public function get($callback = null)
    {
        $started = hrtime(true);
        $acquired = $this->inner->get();

        $this->recorder->lock($acquired ? 'lock-acquired' : 'lock-blocked', $this->store, $this->key, $this->elapsed($started), $this->seconds);

        if ($acquired && is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        return $acquired;
    }

    public function block($seconds, $callback = null)
    {
        $started = hrtime(true);

        try {
            $this->inner->block($seconds);
        } catch (LockTimeoutException $exception) {
            $this->recorder->lock('lock-blocked', $this->store, $this->key, $this->elapsed($started), $this->seconds, 'Timed out after '.$seconds.'s waiting for the lock');

            throw $exception;
        }

        $this->recorder->lock('lock-acquired', $this->store, $this->key, $this->elapsed($started), $this->seconds);

        if (is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        return true;
    }

    public function release()
    {
        $released = $this->inner->release();

        if ($released) {
            $this->recorder->lock('lock-released', $this->store, $this->key, null, null);
        }

        return $released;
    }

    public function owner()
    {
        return $this->inner->owner();
    }

    public function forceRelease()
    {
        $this->inner->forceRelease();

        $this->recorder->lock('lock-released', $this->store, $this->key, null, null);
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->inner->{$method}(...$parameters);

        return $result === $this->inner ? $this : $result;
    }

    private function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1000);
    }
}
