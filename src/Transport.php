<?php

namespace Cyberwizard\WatchtowerAgent;

use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Container\Container;
use JsonSerializable;
use Laravel\Nightwatch\Core;
use Throwable;
use WeakMap;

/**
 * The only class that talks to Nightwatch's Core. Ingest is internal to Nightwatch, so a change
 * there should only ever need a change here.
 */
class Transport implements RecordTransport
{
    /**
     * Each Core's own sample rates before Watch Tower changed any, so an unset rate restores them.
     *
     * @var WeakMap<Core<mixed>, array<string, mixed>>|null
     */
    private static ?WeakMap $originalSampling = null;

    public function __construct(private Container $container) {}

    public function send(array $record): bool
    {
        $core = $this->core();

        if ($core === null) {
            return false;
        }

        try {
            $core->ingest->writeNow($record);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function buffer(array $record): void
    {
        $core = $this->core();

        if ($core === null) {
            return;
        }

        try {
            $core->ingest->write($record);
        } catch (Throwable) {
            //
        }
    }

    public function execution(): ?array
    {
        $core = $this->core();

        if ($core === null) {
            return null;
        }

        try {
            $state = $core->executionState;

            return [
                'trace_id' => $this->scalar($state->trace),
                'execution_source' => (string) $state->source,
                'execution_id' => (string) $state->id,
                'execution_stage' => (string) $state->stage->value,
                'user' => $this->scalar($state->user->id()),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function sample(): void
    {
        $core = $this->core();

        if ($core === null) {
            return;
        }

        try {
            // Core::sample() is Nightwatch's public API; a rate of 1.0 always keeps the execution.
            $core->sample(1.0);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Set Nightwatch's sample rates (0..1) for the executions that start from now on. A null
     * rate restores the app's own value. Nightwatch reads these when a request, command or
     * scheduled task starts, and for exceptions in executions it did not sample.
     *
     * @param  array<string, float|null>  $rates
     */
    public function applySampling(array $rates): void
    {
        $core = $this->core();

        if ($core === null) {
            return;
        }

        try {
            self::$originalSampling ??= new WeakMap;
            self::$originalSampling[$core] ??= (array) ($core->config['sampling'] ?? []);
            $original = self::$originalSampling[$core];

            foreach ($rates as $key => $rate) {
                if ($rate === null) {
                    if (array_key_exists($key, $original)) {
                        $core->config['sampling'][$key] = $original[$key];
                    }

                    continue;
                }

                $core->config['sampling'][$key] = max(0.0, min(1.0, (float) $rate));
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * Run a callback with Nightwatch paused, so the agent's own calls home (rules, actions,
     * browser proxying) never show up as the host app's outgoing requests or queries.
     * The callback's exceptions belong to the caller.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function ignoring(callable $callback): mixed
    {
        $core = null;

        try {
            $container = ContainerInstance::getInstance();
            $core = (new self($container))->core();
        } catch (Throwable) {
            //
        }

        return $core === null ? $callback() : $core->ignore($callback);
    }

    /**
     * Nightwatch defers some values (trace id in the console, user id before the guard
     * resolves) as LazyValue objects; records must hold plain scalars, so resolve them now.
     */
    private function scalar(mixed $value): string
    {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Core when it is bound, enabled and not paused.
     *
     * @return Core<mixed>|null
     */
    private function core(): ?Core
    {
        try {
            if (! $this->container->bound(Core::class)) {
                return null;
            }

            $core = $this->container->make(Core::class);

            if (! $core instanceof Core || ! $core->enabled() || $core->paused()) {
                return null;
            }

            return $core;
        } catch (Throwable) {
            return null;
        }
    }
}
