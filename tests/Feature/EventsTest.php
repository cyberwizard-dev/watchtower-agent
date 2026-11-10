<?php

use App\Events\OrderCancelled;
use App\Events\OrderShipped;
use App\Listeners\Calls;
use App\Listeners\FailingListener;
use App\Listeners\FailingQueuedListener;
use App\Listeners\QueuedShipmentListener;
use App\Listeners\SendShipmentNotification;
use App\Models\Order;
use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Events\EventRecorder;
use Cyberwizard\WatchtowerAgent\Events\InstrumentedDispatcher;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Tests\Support\FakeIngest;
use Cyberwizard\WatchtowerAgent\Tests\Support\QueueTables;
use Cyberwizard\WatchtowerAgent\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Laravel\Nightwatch\Core;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    Calls::$calls = [];
    $this->transport = $this->fakeTransport();
});

it('records an application event with its listeners, matching the contract', function () {
    Event::listen(OrderShipped::class, SendShipmentNotification::class);
    Event::listen(OrderShipped::class, QueuedShipmentListener::class);
    config(['queue.default' => 'null']);

    OrderShipped::dispatch(new Order(['id' => 42]), ['note' => 'fragile', 'api_key' => 'sk_live_1']);

    $records = $this->transport->bufferedOfType('wt-event');

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-event');

    $record = $records[0];
    $payload = json_decode($record['payload'], true);

    expect($record)->toMatchArray([
        'name' => OrderShipped::class,
        'class' => OrderShipped::class,
        'group' => hash('xxh128', OrderShipped::class),
        'broadcast' => false,
        'queued_listeners' => 1,
        'truncated' => false,
        'trace_id' => $this->transport->execution['trace_id'],
        'execution_id' => $this->transport->execution['execution_id'],
    ])->and($record['duration'])->toBeGreaterThanOrEqual($record['listeners'][0]['duration'])
        // Models are references, sensitive keys are masked.
        ->and($payload)->toBe([
            'password' => '[redacted]',
            'order' => ['class' => Order::class, 'key' => 42],
            'meta' => ['note' => 'fragile', 'api_key' => '[redacted]'],
        ])
        ->and(array_column($record['listeners'], 'status'))->toBe(['ok', 'queued']);
});

it('records string events with their payload', function () {
    Event::listen('order.archived', fn () => null);

    Event::dispatch('order.archived', [['id' => 3, 'token' => 'abc']]);

    $record = $this->transport->bufferedOfType('wt-event')[0];

    expect($record['class'])->toBeNull()
        ->and(json_decode($record['payload'], true))->toBe([['id' => 3, 'token' => '[redacted]']]);
});

it('records a failing listener and rethrows its exception unchanged', function () {
    Event::listen(OrderCancelled::class, FailingListener::class);

    expect(fn () => event(new OrderCancelled))->toThrow(RuntimeException::class, 'Listener exploded with password=hunter2');

    $listener = $this->transport->bufferedOfType('wt-event')[0]['listeners'][0];

    expect($listener['status'])->toBe('failed')
        ->and($listener['exception'])->toBe('RuntimeException: Listener exploded with password=[redacted]')
        ->and($listener['duration'])->toBeInt();
});

it('skips framework, package and string-prefixed events by default', function () {
    cache()->get('a');
    event('eloquent.saving: '.Order::class, [new Order]);
    event('creating: some.view');
    event('composing: some.view');
    event(new CommandFinished('x', new ArrayInput([]), new NullOutput, 0));
    event('Laravel\\Nightwatch\\Something');

    expect($this->transport->bufferedOfType('wt-event'))->toBe([]);
});

it('honours allow and deny patterns', function () {
    config(['watchtower-agent.events.deny' => ['App\\Events\\OrderC*']]);
    app()->forgetInstance(EventRecorder::class);

    event(new OrderCancelled);
    event('order.archived');

    expect(array_column($this->transport->bufferedOfType('wt-event'), 'name'))->toBe(['order.archived']);
});

it('lets allow patterns override the default deny list', function () {
    $recorder = app(EventRecorder::class);
    config(['watchtower-agent.events.allow' => ['Illuminate\\Cache\\Events\\CacheMissed']]);
    (fn () => $this->decisions = [])->call($recorder);

    cache()->get('missing');
    event('order.archived');

    expect(array_column($this->transport->bufferedOfType('wt-event'), 'name'))->toBe(['Illuminate\\Cache\\Events\\CacheMissed']);
});

it('samples events', function () {
    config(['watchtower-agent.events.sample_rate' => 0.0]);

    event('order.archived');

    expect($this->transport->bufferedOfType('wt-event'))->toBe([]);
});

it('caps events per execution and emits one summary', function () {
    config(['watchtower-agent.events.max_per_execution' => 3]);

    foreach (range(1, 5) as $i) {
        event('order.archived');
    }
    event(new OrderCancelled);
    event(new OrderCancelled);

    expect($this->transport->bufferedOfType('wt-event'))->toHaveCount(3)
        ->and($this->transport->bufferedOfType('wt-event-summary'))->toBe([]);

    // End of the command: summary flushed before Nightwatch digests.
    event(new CommandFinished('app:import', new ArrayInput([]), new NullOutput, 0));

    $summaries = $this->transport->bufferedOfType('wt-event-summary');

    expect($summaries)->toHaveCount(1);
    Contract::assertRecord($summaries[0], 'wt-event-summary');
    expect($summaries[0]['dropped'])->toBe(4)
        ->and($summaries[0]['names'])->toBe([
            ['name' => 'order.archived', 'count' => 2],
            ['name' => OrderCancelled::class, 'count' => 2],
        ]);

    app(EventRecorder::class)->flushSummary();

    expect($this->transport->bufferedOfType('wt-event-summary'))->toHaveCount(1);
});

it('resets the cap for every new execution', function () {
    config(['watchtower-agent.events.max_per_execution' => 1]);

    event('order.archived');
    event('order.archived');

    $this->transport->newExecution('11111111-1111-4111-8111-111111111111');

    event('order.archived');

    expect(array_column($this->transport->bufferedOfType('wt-event'), 'execution_id'))->toBe([
        '00000000-0000-4000-8000-000000000001',
        '11111111-1111-4111-8111-111111111111',
    ]);

    // The dropped event belonged to the previous execution; it is not reported in this one.
    app(EventRecorder::class)->flushSummary();
    expect($this->transport->bufferedOfType('wt-event-summary'))->toBe([]);
});

it('caps the payload at 8 KB and flags it', function () {
    event('order.archived', [['blob' => str_repeat('x', 1000), 'more' => array_fill(0, 20, str_repeat('y', 1000))]]);

    $record = $this->transport->bufferedOfType('wt-event')[0];

    expect(strlen($record['payload']))->toBeLessThanOrEqual(8192)
        ->and($record['truncated'])->toBeTrue();
});

it('can leave payloads out', function () {
    config(['watchtower-agent.events.capture_payload' => false]);

    event(new OrderCancelled);

    expect($this->transport->bufferedOfType('wt-event')[0])->toMatchArray(['payload' => null, 'truncated' => false]);
});

it('never records events dispatched by the agent itself', function () {
    Guard::quietly(fn () => event('order.archived'));

    // A listener dispatching while the agent emits is ignored too.
    expect($this->transport->bufferedOfType('wt-event'))->toBe([]);
});

it('does nothing when the feature is off or Nightwatch is not capturing', function () {
    config(['watchtower-agent.features.events' => false]);
    event('order.archived');

    config(['watchtower-agent.features.events' => true]);
    $this->transport->execution = null;
    event('order.archived');

    expect($this->transport->buffered)->toBe([]);
});

it('records each queued listener run in the worker with the event id', function () {
    QueueTables::create();
    config(['queue.default' => 'database']);
    Event::listen(OrderShipped::class, QueuedShipmentListener::class);

    event(new OrderShipped(new Order(['id' => 8])));

    $eventId = $this->transport->bufferedOfType('wt-event')[0]['event_id'];

    // A worker is another process: nothing but the job payload carries over.
    Context::flush();
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);

    $runs = $this->transport->bufferedOfType('wt-listener-run');

    expect($runs)->toHaveCount(1);
    Contract::assertRecord($runs[0], 'wt-listener-run');
    expect($runs[0])->toMatchArray([
        'event_id' => $eventId,
        'event_name' => OrderShipped::class,
        'listener' => QueuedShipmentListener::class.'@handle',
        'attempt' => 1,
        'status' => 'ok',
        'exception' => null,
    ])->and($runs[0]['job_id'])->toBeString()
        ->and(Calls::$calls)->toBe(['queued:8']);
});

it('records failed queued listener runs', function () {
    QueueTables::create();
    config(['queue.default' => 'database']);
    Event::listen(OrderCancelled::class, FailingQueuedListener::class);

    event(new OrderCancelled);
    Context::flush();
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));

    $run = $this->transport->bufferedOfType('wt-listener-run')[0];

    expect($run['status'])->toBe('failed')
        ->and($run['exception'])->toBe('RuntimeException: Queued listener failed');
});

it('reads the listener from the payload when the job carries no context', function () {
    QueueTables::create();
    config(['queue.default' => 'database']);
    Event::listen(OrderCancelled::class, FailingQueuedListener::class);

    Guard::quietly(fn () => event(new OrderCancelled));
    Context::flush();
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));

    expect($this->transport->bufferedOfType('wt-listener-run')[0])->toMatchArray([
        'event_id' => null,
        'event_name' => OrderCancelled::class,
        'listener' => FailingQueuedListener::class.'@handle',
    ]);
});

it('captures dispatches only when listener timing is off', function () {
    putenv('WATCHTOWER_AGENT_EVENTS_LISTENER_TIMING=false');
    $_ENV['WATCHTOWER_AGENT_EVENTS_LISTENER_TIMING'] = 'false';

    try {
        $this->refreshApplication();
        $this->app->make(Core::class)->ingest = new FakeIngest;
        $this->transport = $this->fakeTransport();

        expect(app('events'))->not->toBeInstanceOf(InstrumentedDispatcher::class)
            ->and(app(EventRecorder::class)->mode)->toBe('dispatch-only');

        Event::listen(OrderCancelled::class, fn () => null);
        event(new OrderCancelled);

        $record = $this->transport->bufferedOfType('wt-event')[0];

        Contract::assertRecord($record, 'wt-event');
        expect($record['listeners'])->toBe([])->and($record['duration'])->toBe(0);
    } finally {
        putenv('WATCHTOWER_AGENT_EVENTS_LISTENER_TIMING');
        unset($_ENV['WATCHTOWER_AGENT_EVENTS_LISTENER_TIMING']);
    }
});

it('writes in-execution records to the Nightwatch buffer with the real execution header', function () {
    $this->app->forgetInstance(RecordTransport::class);
    $this->app->singleton(RecordTransport::class, Transport::class);
    $this->app->forgetInstance(Recorder::class);

    event('order.archived');

    $records = array_values(array_filter($this->ingest->written, fn ($record) => ($record['t'] ?? null) === 'wt-event'));
    $state = app(Core::class)->executionState;

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-event');
    expect($records[0]['execution_source'])->toBe($state->source)
        ->and($records[0]['execution_id'])->toBe($state->id)
        ->and($records[0]['execution_stage'])->toBe($state->stage->value)
        ->and($this->ingest->sentNow)->toBe([]);
});
