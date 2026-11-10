<?php

/*
 * Spike: can the event dispatcher be decorated safely? InstrumentedDispatcher replaces the
 * container's "events" instance with a subclass that shares every property with the original
 * by reference. These tests prove it coexists with Nightwatch's listeners, Telescope-style
 * wildcard listeners that introspect getListeners(), Event::fake(), queued listeners,
 * subscribers, Event::until(), after-commit events and old references to the dispatcher.
 */

use App\Events\OrderCancelled;
use App\Events\OrderCommitted;
use App\Events\OrderShipped;
use App\Listeners\Calls;
use App\Listeners\OrderSubscriber;
use App\Listeners\QueuedShipmentListener;
use App\Listeners\SendShipmentNotification;
use App\Listeners\StopPropagation;
use App\Models\Order;
use Cyberwizard\WatchtowerAgent\Events\EventRecorder;
use Cyberwizard\WatchtowerAgent\Events\InstrumentedDispatcher;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Calls::$calls = [];
    $this->transport = $this->fakeTransport();
});

function wtEvents(object $test): array
{
    return $test->transport->bufferedOfType('wt-event');
}

it('replaces the dispatcher with the instrumented subclass everywhere it is resolved', function () {
    expect(app('events'))->toBeInstanceOf(InstrumentedDispatcher::class)
        ->and(app(DispatcherContract::class))->toBe(app('events'))
        ->and(Event::getFacadeRoot())->toBe(app('events'))
        ->and(app(EventRecorder::class)->mode)->toBe('instrumented');
});

it('still runs Nightwatch\'s own listeners and records', function () {
    cache()->put('foo', 'bar');
    cache()->get('foo');

    $types = array_column($this->ingest->written, 't');

    expect($types)->toContain('cache-event');
});

it('shares listener state with old references to the original dispatcher', function () {
    // Something (the router, the console kernel, Nightwatch's ingest) captured the stock
    // dispatcher before the agent wrapped it.
    $stock = new Dispatcher(app());
    $stock->listen(OrderCancelled::class, fn () => Calls::$calls[] = 'before-wrap');

    $wrapped = InstrumentedDispatcher::wrap($stock, app(EventRecorder::class));

    $stock->listen(OrderCancelled::class, fn () => Calls::$calls[] = 'registered-on-old');
    $wrapped->dispatch(new OrderCancelled);

    $wrapped->listen('custom.thing', fn () => Calls::$calls[] = 'registered-on-new');
    $stock->dispatch('custom.thing');

    $wrapped->forget(OrderCancelled::class);

    expect(Calls::$calls)->toBe(['before-wrap', 'registered-on-old', 'registered-on-new'])
        ->and($stock->hasListeners(OrderCancelled::class))->toBeFalse()
        ->and(wtEvents($this))->toHaveCount(1);
});

it('times sync listeners and keeps their order, return values and propagation', function () {
    Event::listen(OrderShipped::class, SendShipmentNotification::class);
    Event::listen(OrderShipped::class, StopPropagation::class);
    Event::listen(OrderShipped::class, fn () => Calls::$calls[] = 'never');

    $responses = event(new OrderShipped(new Order(['id' => 42])));

    expect(Calls::$calls)->toBe(['notification:42', 'stop'])
        ->and($responses)->toBe([null]);

    $listeners = wtEvents($this)[0]['listeners'];

    expect(array_column($listeners, 'listener'))->toBe([
        SendShipmentNotification::class.'@handle',
        StopPropagation::class.'@handle',
    ])->and($listeners[0]['duration'])->toBeGreaterThanOrEqual(1000)
        ->and($listeners[0]['status'])->toBe('ok');
});

it('works with Event::until() halting on the first response', function () {
    Event::listen('quote.requested', fn () => null);
    Event::listen('quote.requested', fn () => 'first');
    Event::listen('quote.requested', fn () => 'second');

    expect(Event::until('quote.requested', ['amount' => 10]))->toBe('first');

    $record = wtEvents($this)[0];

    expect($record['name'])->toBe('quote.requested')
        ->and($record['class'])->toBeNull()
        ->and($record['listeners'])->toHaveCount(2);
});

it('lets a Telescope-style wildcard listener introspect the listeners', function () {
    Event::listen(OrderShipped::class, SendShipmentNotification::class);
    Event::listen(OrderShipped::class, fn (OrderShipped $event) => null);

    $seen = [];

    // Telescope's EventWatcher: listen('*'), then reflect getListeners() closures for "listener".
    Event::listen('*', function (string $name) use (&$seen) {
        if ($name !== OrderShipped::class) {
            return;
        }

        foreach (app('events')->getListeners($name) as $closure) {
            $listener = (new ReflectionFunction($closure))->getStaticVariables()['listener'];
            $seen[] = is_string($listener) ? $listener : get_debug_type($listener);
        }
    });

    event(new OrderShipped(new Order(['id' => 1])));

    expect($seen)->toContain(SendShipmentNotification::class, 'Closure')
        ->and(wtEvents($this))->toHaveCount(1);
});

it('keeps Event::fake() and its assertions working', function () {
    Event::listen(OrderShipped::class, SendShipmentNotification::class);

    Event::fake([OrderShipped::class]);

    event(new OrderShipped(new Order(['id' => 3])));
    event(new OrderCancelled(9));

    Event::assertDispatched(OrderShipped::class);
    Event::assertListening(OrderShipped::class, SendShipmentNotification::class);

    // The faked event never reached a listener, so it is not recorded; the real one is.
    expect(Calls::$calls)->toBe([])
        ->and(array_column(wtEvents($this), 'name'))->toBe([OrderCancelled::class]);
});

it('records queued listeners as queued and pushes the real job', function () {
    Queue::fake();
    Event::listen(OrderShipped::class, QueuedShipmentListener::class);

    event(new OrderShipped(new Order(['id' => 5])));

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === QueuedShipmentListener::class);

    $record = wtEvents($this)[0];

    expect($record['queued_listeners'])->toBe(1)
        ->and($record['listeners'][0])->toBe([
            'listener' => QueuedShipmentListener::class.'@handle',
            'queued' => true,
            'duration' => null,
            'status' => 'queued',
            'exception' => null,
        ]);
});

it('times subscriber methods', function () {
    Event::subscribe(OrderSubscriber::class);

    event(new OrderCancelled(4));

    expect(Calls::$calls)->toBe(['subscriber:4'])
        ->and(wtEvents($this)[0]['listeners'][0]['listener'])->toBe(OrderSubscriber::class.'@onCancelled');
});

it('records after-commit events when the transaction commits', function () {
    Event::listen(OrderCommitted::class, fn () => Calls::$calls[] = 'committed');

    DB::transaction(function () {
        event(new OrderCommitted);

        expect(Calls::$calls)->toBe([]);
    });

    expect(Calls::$calls)->toBe(['committed'])
        ->and(array_column(wtEvents($this), 'name'))->toBe([OrderCommitted::class]);
});

it('records deferred events when they are flushed', function () {
    if (! method_exists(Dispatcher::class, 'defer')) {
        $this->markTestSkipped('Event::defer() needs Laravel 12.x+.');
    }

    Event::listen(OrderCancelled::class, fn () => Calls::$calls[] = 'deferred');

    Event::defer(function () {
        event(new OrderCancelled);

        expect(Calls::$calls)->toBe([]);
    });

    expect(Calls::$calls)->toBe(['deferred'])
        ->and(wtEvents($this))->toHaveCount(1);
});

it('attributes listeners of nested dispatches to their own event', function () {
    Event::listen(OrderShipped::class, function () {
        event(new OrderCancelled(2));
        cache()->get('x'); // a framework event in between is ignored
    });
    Event::listen(OrderCancelled::class, fn () => null);

    event(new OrderShipped(new Order(['id' => 1])));

    $records = collect(wtEvents($this))->keyBy('name');

    expect($records)->toHaveCount(2)
        ->and($records[OrderShipped::class]['listeners'])->toHaveCount(1)
        ->and($records[OrderCancelled::class]['listeners'])->toHaveCount(1);
});

it('falls back to dispatch-only capture when the dispatcher was already replaced', function () {
    $custom = new class(app()) extends Dispatcher {};

    expect(InstrumentedDispatcher::wrap($custom, app(EventRecorder::class)))->toBeNull();
});
