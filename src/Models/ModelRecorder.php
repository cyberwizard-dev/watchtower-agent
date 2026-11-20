<?php

namespace Cyberwizard\WatchtowerAgent\Models;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;

/**
 * Eloquent model activity: which models were touched, the queries and relations that touched
 * them, and the lifecycle events that fired.
 *
 * Nothing is sent per query or per event. The recorder keeps per-execution aggregates in
 * memory and emits one `wt-model` record when the request, job, command or task ends, so an
 * eager load of 500 models costs the same as one of 5. Lifecycle events and query groups are
 * kept as bounded samples for the timeline; counts and durations are exact.
 *
 * Queries are attributed to a model through the execution stack: the nearest Eloquent
 * builder or relation the query ran under. This also tells eager from lazy loading and names
 * the relation, without asking Laravel for anything it does not already expose.
 */
class ModelRecorder
{
    /**
     * Model events recorded, in the order Watch Tower ranks them.
     *
     * @var list<string>
     */
    public const OPERATIONS = [
        'retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved',
        'deleting', 'deleted', 'restoring', 'restored', 'forceDeleting', 'forceDeleted',
    ];

    /**
     * Frames that mean the query was loaded as part of an eager load.
     *
     * @var list<string>
     */
    private const EAGER_FRAMES = ['getEager', 'eagerLoadRelations', 'addEagerConstraints', 'loadRelations'];

    /**
     * Frames that mean the query was loaded on demand from a model.
     *
     * @var list<string>
     */
    private const LAZY_FRAMES = ['getResults', 'getRelationshipFromMethod'];

    /** @var array<string, array<string, mixed>> */
    private array $models = [];

    /** @var array<string, array<string, array<string, mixed>>> model => relation key => stats */
    private array $relations = [];

    /** @var array<string, array<string, array<string, mixed>>> model => group key => stats */
    private array $queries = [];

    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var array<string, array{group: string, duration: int, at: float}> */
    private array $lastQuery = [];

    /** @var \WeakMap<EloquentBuilder, array<string, array{0: string, 1: string}>>|null */
    private static ?\WeakMap $eagerSignatures = null;

    private ?string $execution = null;

    public function __construct(private Container $container) {}

    /**
     * A query ran. Attribute it to the model and relation it came from and fold it into the
     * execution's aggregates. Raw query-builder queries have no model and are ignored.
     */
    public function queryExecuted(QueryExecuted $event): void
    {
        if (! $this->enabled() || Guard::active()) {
            return;
        }

        Guard::safely(function () use ($event): void {
            $execution = $this->recorder()->execution();

            if ($execution === null) {
                return;
            }

            $this->sync($execution['execution_id']);

            $origin = $this->origin();

            if ($origin === null || $this->excluded($origin['model'], $origin['table'])) {
                return;
            }

            $duration = (int) round($event->time * 1000);
            $group = $this->groupHash($event);
            $relation = $origin['relation'];
            $load = $origin['load'];
            $key = $origin['model'];
            $connection = $event->connectionName ?: $origin['connection'];

            $this->touch($key, $origin['table'], $connection);

            $this->models[$key]['queries']++;
            $this->models[$key]['duration'] += $duration;
            $this->models[$key]['max_duration'] = max($this->models[$key]['max_duration'], $duration);
            $this->models[$key]['last_at'] = microtime(true);

            if ($relation !== null) {
                $relationKey = $relation.'|'.($origin['related'] ?? '');

                $this->relations[$key][$relationKey] ??= [
                    'relation' => $relation,
                    'related' => $origin['related'],
                    'eager' => 0,
                    'lazy' => 0,
                    'queries' => 0,
                    'duration' => 0,
                    'max_duration' => 0,
                ];

                $this->relations[$key][$relationKey]['queries']++;
                $this->relations[$key][$relationKey]['duration'] += $duration;
                $this->relations[$key][$relationKey]['max_duration'] = max($this->relations[$key][$relationKey]['max_duration'], $duration);
                $this->relations[$key][$relationKey][$load === 'eager' ? 'eager' : 'lazy']++;
            }

            $groupKey = $group.'|'.($relation ?? '').'|'.($load ?? 'direct');

            $this->queries[$key][$groupKey] ??= [
                'group' => $group,
                'relation' => $relation,
                'related' => $origin['related'],
                'load' => $load,
                'calls' => 0,
                'duration' => 0,
                'max_duration' => 0,
                'first_at' => microtime(true),
            ];

            $this->queries[$key][$groupKey]['calls']++;
            $this->queries[$key][$groupKey]['duration'] += $duration;
            $this->queries[$key][$groupKey]['max_duration'] = max($this->queries[$key][$groupKey]['max_duration'], $duration);

            $this->lastQuery[$connection.'|'.$origin['table']] = ['group' => $group, 'duration' => $duration, 'at' => microtime(true)];
        });
    }

    /**
     * One of the `eloquent.*` lifecycle events fired, e.g. `eloquent.retrieved: App\Models\User`.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function modelEvent(string $name, array $payload = []): void
    {
        if (! $this->enabled() || Guard::active() || ! str_starts_with($name, 'eloquent.')) {
            return;
        }

        Guard::safely(function () use ($name, $payload): void {
            $model = $payload[0] ?? null;

            if (! $model instanceof Model) {
                return;
            }

            [$event] = explode(': ', substr($name, strlen('eloquent.')), 2) + [1 => ''];

            if (! in_array($event, self::OPERATIONS, true)) {
                return;
            }

            $execution = $this->recorder()->execution();

            if ($execution === null) {
                return;
            }

            $this->sync($execution['execution_id']);

            $key = $model::class;

            if ($this->excluded($key, $model->getTable())) {
                return;
            }

            $this->touch($key, $model->getTable(), (string) ($model->getConnectionName() ?? ''));

            $this->models[$key]['ops'][$event] = ($this->models[$key]['ops'][$event] ?? 0) + 1;
            $this->models[$key]['operations']++;
            $this->models[$key]['last_at'] = microtime(true);

            $max = max(0, (int) config('watchtower-agent.models.max_events', 50));

            if ($max === 0 || count($this->events) >= $max) {
                return;
            }

            $last = $this->lastQuery[$model->getConnection()->getName().'|'.$model->getTable()] ?? null;

            $this->events[] = [
                'model' => $key,
                'operation' => $event,
                'key' => $this->maskKey($model->getKey()),
                'group' => $last['group'] ?? null,
                'duration' => $last['duration'] ?? null,
                'at' => microtime(true),
            ];
        });
    }

    /**
     * Emit the execution's `wt-model` record and start over. Called at the end of every
     * request, job, command and scheduled task; safe to call more than once.
     */
    public function flush(): void
    {
        Guard::safely(function (): void {
            $execution = $this->recorder()->execution();
            $models = $this->snapshot($execution['execution_id'] ?? null);

            $this->reset();

            if ($models === null || ! $this->enabled()) {
                return;
            }

            $this->recorder()->inExecution('wt-model', $models);
        });
    }

    /**
     * Start a new execution, e.g. on a queue worker loop or an Octane request.
     */
    public function reset(): void
    {
        $this->models = [];
        $this->relations = [];
        $this->queries = [];
        $this->events = [];
        $this->lastQuery = [];
        $this->execution = null;
    }

    /**
     * The record payload, or null when nothing was recorded or the execution changed since
     * the activity was collected (a new loop, an unsampled execution).
     *
     * @return array<string, mixed>|null
     */
    private function snapshot(?string $execution): ?array
    {
        if ($execution === null || $execution !== $this->execution || $this->models === []) {
            return null;
        }

        $limit = max(0, (int) config('watchtower-agent.models.max_query_groups', 100));
        $models = [];
        $kept = $this->models;
        uasort($kept, fn (array $a, array $b): int => [$b['queries'], $b['operations']] <=> [$a['queries'], $a['operations']]);
        $kept = array_slice($kept, 0, max(0, (int) config('watchtower-agent.models.max_models', 50)), true);
        $groupBudget = 500;

        foreach ($kept as $class => $stats) {
            $relations = $this->relations[$class] ?? [];

            uasort($relations, fn (array $a, array $b): int => [$b['queries'], $b['duration']] <=> [$a['queries'], $a['duration']]);
            $relations = array_values(array_slice($relations, 0, 30));

            $queryGroups = array_values($this->queries[$class] ?? []);

            if ($limit > 0 && count($queryGroups) > $limit) {
                usort($queryGroups, fn (array $a, array $b): int => [$b['duration'], $b['calls']] <=> [$a['duration'], $a['calls']]);
                $queryGroups = array_slice($queryGroups, 0, $limit);
            }

            if ($groupBudget <= 0) {
                $queryGroups = [];
            } elseif (count($queryGroups) > $groupBudget) {
                $queryGroups = array_slice($queryGroups, 0, $groupBudget);
            }

            $groupBudget -= count($queryGroups);

            $models[] = [
                'model' => $class,
                'table' => $stats['table'],
                'connection' => $stats['connection'],
                'operations' => $stats['operations'],
                'ops' => $stats['ops'],
                'queries' => $stats['queries'],
                'duration' => $stats['duration'],
                'max_duration' => $stats['max_duration'],
                'relations' => array_map($this->relationPayload(...), $relations),
                'query_groups' => array_map(fn (array $group): array => [
                    'group' => $group['group'],
                    'relation' => $group['relation'],
                    'related' => $group['related'],
                    'load' => $group['load'],
                    'calls' => $group['calls'],
                    'duration' => $group['duration'],
                    'max_duration' => $group['max_duration'],
                    'first_at' => $group['first_at'],
                ], $queryGroups),
            ];
        }

        return [
            'models' => $models,
            'events' => $this->events,
        ];
    }

    /**
     * @param  array<string, mixed>  $relation
     * @return array<string, mixed>
     */
    private function relationPayload(array $relation): array
    {
        return [
            'name' => $relation['relation'],
            'related' => $relation['related'],
            'eager' => $relation['eager'],
            'lazy' => $relation['lazy'],
            'queries' => $relation['queries'],
            'duration' => $relation['duration'],
            'max_duration' => $relation['max_duration'],
        ];
    }

    private function touch(string $model, string $table, ?string $connection): void
    {
        $this->models[$model] ??= [
            'table' => $table,
            'connection' => $connection ?: null,
            'operations' => 0,
            'ops' => [],
            'queries' => 0,
            'duration' => 0,
            'max_duration' => 0,
            'last_at' => null,
        ];
    }

    /**
     * The Eloquent builder or relation the current query runs under.
     *
     * @return array{model: string, table: string, connection: string|null, relation: string|null, related: string|null, load: string|null}|null
     */
    private function origin(): ?array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 60);
        $relation = null;
        $builder = null;
        $eager = false;
        $lazy = false;
        $relationName = null;

        foreach ($trace as $frame) {
            $object = $frame['object'] ?? null;
            $function = (string) ($frame['function'] ?? '');

            if ($object instanceof Relation && $relation === null) {
                $relation = $object;
            }

            if ($object instanceof EloquentBuilder && $builder === null) {
                $builder = $object;
            }

            if (in_array($function, self::EAGER_FRAMES, true)) {
                $eager = true;
            }

            if (in_array($function, self::LAZY_FRAMES, true)) {
                $lazy = true;
            }

            if ($relationName === null && isset($frame['args'][0]) && is_string($frame['args'][0])) {
                if ($function === 'getRelationshipFromMethod' || $function === 'getRelation') {
                    $relationName = $frame['args'][0];
                }
            }
        }

        if ($relation instanceof Relation) {
            $parent = $relation->getParent();
            $related = $relation->getRelated();

            return [
                'model' => $parent::class,
                'table' => $parent->getTable(),
                'connection' => $parent->getConnectionName(),
                'relation' => $relationName
                    ?? (method_exists($relation, 'getRelationName') ? $relation->getRelationName() : null)
                    ?? $this->eagerRelationName($relation, $trace),
                'related' => $related::class,
                'load' => $eager && ! $lazy ? 'eager' : 'lazy',
            ];
        }

        if ($builder instanceof EloquentBuilder) {
            $model = $builder->getModel();

            return [
                'model' => $model::class,
                'table' => $model->getTable(),
                'connection' => $model->getConnectionName(),
                'relation' => null,
                'related' => null,
                'load' => 'direct',
            ];
        }

        return null;
    }

    /**
     * The name of an eagerly loaded relation. Eager loading calls `getRelation($name)` before
     * the query runs, so the name is no longer on the stack; the loading builder still holds
     * the eager-load map, and a relation built from each name is compared with the one that
     * actually ran.
     *
     * @param  list<array<string, mixed>>  $trace
     */
    private function eagerRelationName(Relation $relation, array $trace): ?string
    {
        foreach ($trace as $frame) {
            $builder = $frame['object'] ?? null;

            if (! $builder instanceof EloquentBuilder || $builder->getEagerLoads() === []) {
                continue;
            }

            foreach ($this->eagerSignatures($builder) as $name => $signature) {
                if ($signature === [$relation::class, $relation->getRelated()::class]) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Relation name => [relation class, related model class] for a builder's eager loads,
     * built once per builder (defining a relation can run application code).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function eagerSignatures(EloquentBuilder $builder): array
    {
        self::$eagerSignatures ??= new \WeakMap;

        if (isset(self::$eagerSignatures[$builder])) {
            return self::$eagerSignatures[$builder];
        }

        $signatures = [];

        foreach (array_keys($builder->getEagerLoads()) as $name) {
            $candidate = Guard::safely(fn (): ?Relation => $builder->getRelation($name));

            if ($candidate instanceof Relation) {
                $signatures[$name] = [$candidate::class, $candidate->getRelated()::class];
            }
        }

        return self::$eagerSignatures[$builder] = $signatures;
    }

    /**
     * Nightwatch's query grouping hash, recomputed so the model rows can be joined to the
     * query records Nightwatch stores without duplicating SQL.
     */
    private function groupHash(QueryExecuted $event): string
    {
        $sql = $event->sql;

        if (in_array($event->connection->getDriverName(), ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv', 'singlestore'], true)) {
            $sql = (string) preg_replace('/in \([\d?\s,]+\)/', 'in (...?)', $sql);

            if (str_contains($sql, 'insert')) {
                $sql = (string) preg_replace('/values [(?,\s)]+/', 'values ...', $sql);
            }
        }

        return hash('xxh128', "{$event->connectionName},{$sql}");
    }

    private function excluded(string $model, string $table): bool
    {
        foreach ((array) config('watchtower-agent.models.exclude', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && Str::is($pattern, $model)) {
                return true;
            }
        }

        return in_array($table, (array) config('watchtower-agent.models.exclude_tables', []), true);
    }

    /**
     * Mask a model key: "masked" (default) replaces id-like parts with "*", "hidden" drops
     * it, "full" keeps it.
     */
    private function maskKey(mixed $key): ?string
    {
        if (! is_scalar($key) || $key === '') {
            return null;
        }

        return match (config('watchtower-agent.models.keys', 'masked')) {
            'full' => (string) $key,
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
                (string) $key,
            ),
        };
    }

    private function sync(string $execution): void
    {
        if ($this->execution !== $execution) {
            $this->reset();
            $this->execution = $execution;
        }
    }

    private function enabled(): bool
    {
        return WatchtowerAgent::feature('models');
    }

    private function recorder(): Recorder
    {
        return $this->container->make(Recorder::class);
    }
}
