<?php

namespace Cyberwizard\WatchtowerAgent\Events;

use Closure;
use Illuminate\Events\Dispatcher;
use ReflectionClass;
use Throwable;

/**
 * Laravel's own dispatcher with two hooks: every dispatch opens a frame on the EventRecorder,
 * and every non-wildcard listener is timed. It shares ALL state with the dispatcher it
 * replaces (each property is bound by reference), so a listener registered through an old
 * reference to the original dispatcher is still seen here and vice versa: no split brain.
 *
 * Listener closures keep the raw listener in a static variable named "listener", which is what
 * Event::assertListening() and Telescope's event watcher read with reflection.
 */
class InstrumentedDispatcher extends Dispatcher
{
    private EventRecorder $watchtower;

    /**
     * Wrap a stock dispatcher. Returns null when the dispatcher is not exactly
     * Illuminate\Events\Dispatcher (another package already replaced it) or its state can't be
     * shared, in which case the caller falls back to dispatch-only capture.
     */
    public static function wrap(mixed $original, EventRecorder $recorder): ?self
    {
        if (! is_object($original) || $original::class !== Dispatcher::class) {
            return null;
        }

        try {
            $properties = [];

            foreach ((new ReflectionClass(Dispatcher::class))->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                if ($property->isReadOnly()) {
                    return null;
                }

                $properties[] = $property->getName();
            }

            /** @var self $instance */
            $instance = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();

            Closure::bind(function (Dispatcher $from) use ($properties): void {
                foreach ($properties as $name) {
                    $this->{$name} = &$from->{$name};
                }
            }, $instance, Dispatcher::class)($original);

            $instance->watchtower = $recorder;

            return $instance;
        } catch (Throwable) {
            return null;
        }
    }

    public function makeListener($listener, $wildcard = false)
    {
        $made = parent::makeListener($listener, $wildcard);

        if ($wildcard) {
            return $made;
        }

        $recorder = $this->watchtower;

        return static function ($event, $payload) use ($listener, $made, $recorder) {
            return $recorder->runListener($listener, $made, $event, $payload);
        };
    }

    protected function invokeListeners($event, $payload, $halt = false)
    {
        $frame = $this->watchtower->begin($event, $payload);

        try {
            return parent::invokeListeners($event, $payload, $halt);
        } finally {
            $this->watchtower->end($frame);
        }
    }
}
