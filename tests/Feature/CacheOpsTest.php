<?php

use Cyberwizard\WatchtowerAgent\Cache\CacheOpRecorder;
use Cyberwizard\WatchtowerAgent\Cache\InstrumentedLock;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\CacheLocksFlushed;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->transport = $this->fakeTransport();
});

function cacheOps(object $test): array
{
    return $test->transport->bufferedOfType('wt-cache-op');
}

it('records store flushes from clear(), flush() and tagged flushes', function () {
    if (! class_exists(CacheFlushed::class)) {
        $this->markTestSkipped('Cache flush events need Laravel 12.x+.');
    }

    Cache::clear();
    Cache::flush();
    Cache::tags(['orders'])->flush();
    Cache::store('array')->put('kept', true);

    $records = cacheOps($this);

    expect($records)->toHaveCount(3);

    foreach ($records as $record) {
        Contract::assertRecord($record, 'wt-cache-op');
        expect($record)->toMatchArray(['op' => 'flush', 'store' => 'array', 'driver' => 'array', 'key' => null, 'error' => null])
            ->and($record['duration'])->toBeInt();
    }
});

it('keeps flush() return values and behaviour', function () {
    Cache::put('a', 1);

    expect(Cache::flush())->toBeTrue()->and(Cache::get('a'))->toBeNull();
});

it('records lock flushes', function () {
    if (! class_exists(CacheLocksFlushed::class)) {
        $this->markTestSkipped('Lock flush events need Laravel 12.x+.');
    }

    Cache::store('array')->flushLocks();

    expect(cacheOps($this)[0])->toMatchArray(['op' => 'locks-flush', 'store' => 'array', 'error' => null]);
});

it('records failover from a failing store', function () {
    if (! class_exists(CacheFailedOver::class)) {
        $this->markTestSkipped('The failover cache store needs Laravel 13.x.');
    }

    config([
        'cache.stores.broken' => ['driver' => 'database', 'table' => 'missing_cache_table', 'connection' => 'testing'],
        'cache.stores.failover' => ['driver' => 'failover', 'stores' => ['broken', 'array']],
    ]);

    Cache::store('failover')->put('a', 1);

    $record = cacheOps($this)[0];

    Contract::assertRecord($record, 'wt-cache-op');
    expect($record)->toMatchArray(['op' => 'failover', 'store' => 'broken', 'driver' => 'database'])
        ->and($record['error'])->toStartWith('Illuminate\Database\QueryException');
});

it('records lock acquire, block and release when enabled', function () {
    config(['watchtower-agent.cache_ops.locks' => true]);
    CacheOpRecorder::installLockMacros();

    $lock = Cache::lock('orders:42:import', 10);

    expect($lock)->toBeInstanceOf(InstrumentedLock::class)
        ->and($lock->get())->toBeTrue()
        ->and(Cache::lock('orders:42:import', 10)->get())->toBeFalse()
        ->and($lock->release())->toBeTrue()
        ->and(Cache::lock('orders:43:import')->get(fn () => 'ran'))->toBe('ran');

    Cache::lock('busy', 10)->get();
    expect(fn () => Cache::lock('busy', 10)->betweenBlockedAttemptsSleepFor(1)->block(0))->toThrow(LockTimeoutException::class);

    $records = cacheOps($this);

    foreach ($records as $record) {
        Contract::assertRecord($record, 'wt-cache-op');
    }

    expect(array_column($records, 'op'))->toBe([
        'lock-acquired', 'lock-blocked', 'lock-released',
        'lock-acquired', 'lock-released',
        'lock-acquired', 'lock-blocked',
    ])->and($records[0])->toMatchArray(['key' => 'orders:*:import', 'ttl' => 10, 'store' => 'array']);
});

it('returns the store\'s own lock when lock capture is off', function () {
    CacheOpRecorder::installLockMacros();
    config(['watchtower-agent.cache_ops.locks' => false]);

    expect(Cache::lock('x', 1))->not->toBeInstanceOf(InstrumentedLock::class)
        ->and(cacheOps($this))->toBe([]);
});

it('masks, hides or keeps keys', function () {
    expect(CacheOpRecorder::maskKey('user:123:profile'))->toBe('user:*:profile')
        ->and(CacheOpRecorder::maskKey('session:9f1c2d3e-1111-4222-8333-123456789abc'))->toBe('session:*')
        ->and(CacheOpRecorder::maskKey('reset:jane@example.com'))->toBe('reset:*');

    config(['watchtower-agent.cache_ops.keys' => 'hidden']);
    expect(CacheOpRecorder::maskKey('user:123'))->toBeNull();

    config(['watchtower-agent.cache_ops.keys' => 'full']);
    expect(CacheOpRecorder::maskKey('user:123'))->toBe('user:123');
});

it('does not duplicate Nightwatch cache events', function () {
    Cache::put('a', 1);
    Cache::get('a');
    Cache::forget('a');

    expect(cacheOps($this))->toBe([])
        ->and(array_column($this->ingest->written, 't'))->toContain('cache-event');
});

it('does nothing when the feature is off', function () {
    config(['watchtower-agent.features.cache_ops' => false]);

    Cache::clear();

    expect(cacheOps($this))->toBe([]);
});
