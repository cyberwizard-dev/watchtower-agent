<?php

namespace Cyberwizard\WatchtowerAgent\Schedule;

use Cyberwizard\ScheduleCatchup\Support\ScheduleEventKey;
use Cyberwizard\ScheduleCatchup\Support\ScheduleReplayRegistry;
use Cyberwizard\WatchtowerAgent\Support\TaskGroup;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The full list of scheduled events, as Watch Tower's wt-schedule record expects it.
 */
class ScheduleRegistry
{
    public function __construct(private Container $container) {}

    /**
     * @return list<Event>
     */
    public function events(): array
    {
        try {
            return array_values($this->container->make(Schedule::class)->events());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{group: string, name: string, cron: string, timezone: string, repeat_seconds: int, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, description: ?string, replay_on_catchup: bool, next_run_at: ?float}>
     */
    public function tasks(): array
    {
        $tasks = [];

        foreach ($this->events() as $event) {
            $tasks[] = $this->describe($event);
        }

        return $tasks;
    }

    /**
     * @return array{group: string, name: string, cron: string, timezone: string, repeat_seconds: int, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, description: ?string, replay_on_catchup: bool, next_run_at: ?float}
     */
    public function describe(Event $event): array
    {
        return [
            'group' => TaskGroup::for($event),
            'name' => TaskGroup::name($event),
            'cron' => (string) $event->expression,
            'timezone' => TaskGroup::timezone($event),
            'repeat_seconds' => TaskGroup::repeatSeconds($event),
            'without_overlapping' => (bool) $event->withoutOverlapping,
            'on_one_server' => (bool) $event->onOneServer,
            'run_in_background' => (bool) $event->runInBackground,
            'even_in_maintenance_mode' => (bool) $event->evenInMaintenanceMode,
            'description' => is_string($event->description) && $event->description !== '' ? $event->description : null,
            'replay_on_catchup' => self::replaysOnCatchup($event),
            'next_run_at' => $this->nextRunAt($event),
        ];
    }

    public static function replaysOnCatchup(Event $event): bool
    {
        return class_exists(ScheduleReplayRegistry::class) && ScheduleReplayRegistry::isTagged($event);
    }

    /**
     * The event matching a schedule-catchup ledger key, if it is still scheduled.
     */
    public function findByCatchupKey(string $key): ?Event
    {
        if (! class_exists(ScheduleEventKey::class)) {
            return null;
        }

        foreach ($this->events() as $event) {
            if (ScheduleEventKey::for($event) === $key) {
                return $event;
            }
        }

        return null;
    }

    private function nextRunAt(Event $event): ?float
    {
        try {
            return (float) $event->nextRunDate()->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
