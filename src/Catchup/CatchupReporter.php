<?php

namespace Cyberwizard\WatchtowerAgent\Catchup;

use Carbon\CarbonInterface;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceDeferred;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceFailed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceReplayed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOutageRecorded;
use Cyberwizard\ScheduleCatchup\Support\ScheduleEventKey;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Schedule\ScheduleRegistry;
use Cyberwizard\WatchtowerAgent\Support\OutputTail;
use Cyberwizard\WatchtowerAgent\Support\TaskGroup;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use DateTimeInterface;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reports cyberwizard/schedule-catchup replays (wt-catchup) and outages (wt-outage).
 *
 * schedule-catchup 1.2 dispatches one event per replay outcome, which is used when available.
 * With 1.1 the agent reads its log and outage tables after each catch-up run instead, keeping
 * a cursor in the cache.
 */
class CatchupReporter
{
    public const CURSOR_KEY = 'watchtower-agent:catchup:cursor';

    public function __construct(
        private Recorder $recorder,
        private ScheduleRegistry $registry,
        private Cache $cache,
    ) {}

    public static function usesOccurrenceEvents(): bool
    {
        return match (config('watchtower-agent.catchup.source', 'auto')) {
            'events' => class_exists(ScheduleOccurrenceReplayed::class),
            'tables' => false,
            default => class_exists(ScheduleOccurrenceReplayed::class),
        };
    }

    public function replayed(ScheduleOccurrenceReplayed $event): void
    {
        $this->whenEvents(function () use ($event): void {
            $previousAttempts = $this->loggedAttempts($event->event, $event->scheduledAt);
            $tail = OutputTail::fromEvent($event->event, $this->maxBytes());

            $this->sendCatchup($event->event, [
                'scheduled_for' => $this->timestamp($event->scheduledAt),
                'ran_at' => microtime(true),
                'outcome' => 'replayed',
                'exit_code' => $event->exitCode,
                'attempts' => $previousAttempts + 1,
                'error' => null,
                'duration' => (int) round($event->runtime * 1_000_000),
                'output' => $tail?->output,
                'truncated' => $tail?->truncated ?? false,
            ]);
        });
    }

    public function failed(ScheduleOccurrenceFailed $event): void
    {
        $this->whenEvents(function () use ($event): void {
            $tail = OutputTail::fromEvent($event->event, $this->maxBytes());

            $this->sendCatchup($event->event, [
                'scheduled_for' => $this->timestamp($event->scheduledAt),
                'ran_at' => microtime(true),
                'outcome' => $event->exhausted ? 'exhausted' : 'failed',
                'exit_code' => $event->exitCode,
                'attempts' => $event->attempts,
                'error' => $event->error,
                'duration' => null,
                'output' => $tail?->output,
                'truncated' => $tail?->truncated ?? false,
            ]);
        });
    }

    public function deferred(ScheduleOccurrenceDeferred $event): void
    {
        $this->whenEvents(function () use ($event): void {
            $this->sendCatchup($event->event, [
                'scheduled_for' => $this->timestamp($event->scheduledAt),
                'ran_at' => microtime(true),
                'outcome' => 'deferred',
                'exit_code' => null,
                'attempts' => $this->loggedAttempts($event->event, $event->scheduledAt),
                'error' => null,
                'duration' => null,
                'output' => null,
                'truncated' => false,
            ]);
        });
    }

    public function outage(ScheduleOutageRecorded $event): void
    {
        $this->whenEvents(function () use ($event): void {
            $outage = $event->outage;

            $this->sendOutage($outage->started_at, $outage->ended_at, (int) $outage->minutes);
        });
    }

    /**
     * 1.1 fallback, run after ScheduleCatchupCompleted, ScheduleCatchupFailed and when the
     * schedule-catchup:run command finishes. The cursor makes repeated calls harmless.
     */
    public function sync(object $event): void
    {
        if ($event instanceof CommandFinished && $event->command !== 'schedule-catchup:run') {
            return;
        }

        if (! WatchtowerAgent::feature('catchup') || self::usesOccurrenceEvents()) {
            return;
        }

        try {
            $this->syncTables();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function syncTables(): void
    {
        $prefix = (string) config('schedule-catchup.table_prefix', 'cyber_');
        $logs = $prefix.'schedule_catchup_logs';
        $outages = $prefix.'schedule_catchup_outages';

        $cursor = $this->cursor();

        if (Schema::hasTable($logs)) {
            $rows = DB::table($logs)
                ->where('source', 'catchup')
                ->where('updated_at', '>', $cursor['logs'])
                ->orderBy('updated_at')
                ->orderBy('id')
                ->limit(1000)
                ->get();

            $maxAttempts = max(1, (int) config('schedule-catchup.max_attempts', 3));

            foreach ($rows as $row) {
                $this->sendLogRow((array) $row, $maxAttempts);
                $cursor['logs'] = (string) $row->updated_at;
            }
        }

        if (Schema::hasTable($outages)) {
            $rows = DB::table($outages)
                ->where('id', '>', $cursor['outage_id'])
                ->orderBy('id')
                ->limit(1000)
                ->get();

            foreach ($rows as $row) {
                $this->sendOutage(Carbon::parse($row->started_at), Carbon::parse($row->ended_at), (int) $row->minutes);
                $cursor['outage_id'] = (int) $row->id;
            }
        }

        $this->cache->forever(self::CURSOR_KEY, $cursor);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sendLogRow(array $row, int $maxAttempts): void
    {
        $event = $this->registry->findByCatchupKey((string) $row['command']);

        // A row for an event that is no longer scheduled can't be linked to a task group.
        if ($event === null) {
            return;
        }

        $exitCode = $row['exit_code'] === null ? null : (int) $row['exit_code'];
        $attempts = (int) $row['attempts'];

        $outcome = match (true) {
            $exitCode === 0 => 'replayed',
            $exitCode === null => 'deferred',
            $attempts >= $maxAttempts => 'exhausted',
            default => 'failed',
        };

        $this->sendCatchup($event, [
            'scheduled_for' => $this->timestamp(Carbon::parse($row['scheduled_at'])),
            'ran_at' => $this->timestamp(Carbon::parse($row['ran_at'])),
            'outcome' => $outcome,
            'exit_code' => $exitCode,
            'attempts' => $outcome === 'replayed' ? $attempts + 1 : $attempts,
            'error' => $outcome === 'replayed' || $outcome === 'deferred' ? null : ($row['last_error'] ?? null),
            'duration' => null,
            'output' => null,
            'truncated' => false,
        ]);
    }

    /**
     * @return array{logs: string, outage_id: int}
     */
    private function cursor(): array
    {
        $cursor = $this->cache->get(self::CURSOR_KEY);

        if (is_array($cursor) && isset($cursor['logs'], $cursor['outage_id'])) {
            return ['logs' => (string) $cursor['logs'], 'outage_id' => (int) $cursor['outage_id']];
        }

        $lookback = max(0, (int) config('watchtower-agent.catchup.initial_lookback_hours', 24));

        return [
            'logs' => Carbon::now()->subHours($lookback)->toDateTimeString(),
            'outage_id' => $this->firstOutageIdSince(Carbon::now()->subHours($lookback)),
        ];
    }

    /**
     * Outages are read by id; on the first sync only those that ended inside the lookback are sent.
     */
    private function firstOutageIdSince(DateTimeInterface $since): int
    {
        $table = (string) config('schedule-catchup.table_prefix', 'cyber_').'schedule_catchup_outages';

        if (! Schema::hasTable($table)) {
            return 0;
        }

        $before = DB::table($table)->where('ended_at', '<', $since->format('Y-m-d H:i:s'))->max('id');

        return (int) ($before ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendCatchup(Event $event, array $payload): void
    {
        $this->recorder->send('wt-catchup', [
            'group' => TaskGroup::for($event),
            'name' => TaskGroup::name($event),
            ...$payload,
        ]);
    }

    private function sendOutage(?DateTimeInterface $startedAt, ?DateTimeInterface $endedAt, int $minutes): void
    {
        if ($startedAt === null || $endedAt === null) {
            return;
        }

        $this->recorder->send('wt-outage', [
            'started_at' => $this->timestamp($startedAt),
            'ended_at' => $this->timestamp($endedAt),
            'minutes' => $minutes,
        ]);
    }

    private function loggedAttempts(Event $event, CarbonInterface $scheduledAt): int
    {
        try {
            $table = (string) config('schedule-catchup.table_prefix', 'cyber_').'schedule_catchup_logs';

            return (int) (DB::table($table)
                ->where('command', ScheduleEventKey::for($event))
                ->where('scheduled_at', $scheduledAt->toDateTimeString())
                ->value('attempts') ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function timestamp(DateTimeInterface $date): float
    {
        return (float) $date->format('U.u');
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('watchtower-agent.max_output_bytes', 65536));
    }

    private function whenEvents(callable $callback): void
    {
        if (! WatchtowerAgent::feature('catchup') || ! self::usesOccurrenceEvents()) {
            return;
        }

        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
