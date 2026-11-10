<?php

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Workers\WorkerHeartbeat;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->transport = $this->fakeTransport();
    $this->now = 1_000.0;
    app(WorkerHeartbeat::class)->clock = fn () => $this->now;
});

it('sends starting, throttled alive and stopping heartbeats', function () {
    $options = new WorkerOptions;

    event(new WorkerStarting('redis', 'emails', $options));
    $this->now += 10;
    Event::until(new Looping('redis', 'emails', $options));
    $this->now += 21;
    Event::until(new Looping('redis', 'emails', $options));
    $this->now += 5;
    Event::until(new Looping('redis', 'emails', $options));
    event(new WorkerStopping(0, $options, 'interrupted', 7, null, 12_345_678, 'redis', 'emails'));

    $records = $this->transport->ofType('wt-worker');

    expect(array_column($records, 'status'))->toBe(['starting', 'alive', 'stopping']);

    foreach ($records as $record) {
        Contract::assertRecord($record, 'wt-worker');
    }

    $pid = getmypid();
    expect($records[0])->toMatchArray([
        'worker_id' => gethostname().":{$pid}:1000",
        'host' => (string) gethostname(),
        'pid' => $pid,
        'connection' => 'redis',
        'queue' => 'emails',
        'started_at' => 1000.0,
        'reason' => null,
    ]);

    // Laravel 13 adds jobsProcessed and memoryUsage to WorkerStopping; older versions fall back
    // to the agent's own count and the current memory usage.
    $detailed = property_exists(WorkerStopping::class, 'jobsProcessed');

    expect($records[2])->toMatchArray([
        'status' => 'stopping',
        'reason' => 'interrupted',
        'jobs_processed' => $detailed ? 7 : 0,
    ])->and($records[2]['memory'])->toBe($detailed ? 12_345_678 : $records[2]['memory']);
});

it('returns null from the Looping listener so the worker keeps running', function () {
    $heartbeat = app(WorkerHeartbeat::class);

    expect($heartbeat->looping(new Looping('redis', 'default')))->toBeNull()
        ->and($heartbeat->looping(new Looping('redis', 'default')))->toBeNull();

    // Dispatcher::until() returns the first non-null listener response.
    expect(Event::until(new Looping('redis', 'default')))->toBeNull();
});

it('returns null even when sending fails', function () {
    $this->app->instance(Recorder::class, new class extends Recorder
    {
        public function __construct() {}

        public function send(string $type, array $payload): bool
        {
            throw new RuntimeException('boom');
        }
    });
    $this->app->forgetInstance(WorkerHeartbeat::class);

    expect(Event::until(new Looping('redis', 'default')))->toBeNull();
});

it('does nothing when the workers feature is off', function () {
    config(['watchtower-agent.features.workers' => false]);

    event(new WorkerStarting('redis', 'default', new WorkerOptions));

    expect($this->transport->sent)->toBe([]);
});
