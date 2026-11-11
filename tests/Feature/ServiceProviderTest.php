<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

function agentEvents(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn ($event) => $event->command !== null && str_contains($event->command, 'watchtower:'))
        ->mapWithKeys(fn ($event) => [trim(str($event->command)->after('artisan')->toString(), "' ") => $event])
        ->all();
}

it('registers the commands', function () {
    expect(array_keys(Artisan::all()))->toContain(
        'watchtower:hello',
        'watchtower:schedule-sync',
        'watchtower:queue-snapshot',
        'watchtower:health-report',
        'watchtower:actions',
    );
});

it('schedules the agent commands without overlapping', function () {
    $events = agentEvents();

    expect(array_keys($events))->toEqualCanonicalizing([
        'watchtower:hello',
        'watchtower:schedule-sync',
        'watchtower:queue-snapshot',
        'watchtower:health-report',
    ])
        ->and($events['watchtower:hello']->expression)->toBe('0 * * * *')
        ->and($events['watchtower:schedule-sync']->expression)->toBe('*/10 * * * *')
        ->and($events['watchtower:queue-snapshot']->expression)->toBe('* * * * *')
        ->and($events['watchtower:health-report']->expression)->toBe('* * * * *');

    foreach ($events as $event) {
        expect($event->withoutOverlapping)->toBeTrue();
    }
});

it('schedules nothing when the agent is disabled', function () {
    config(['watchtower-agent.enabled' => false]);

    expect(agentEvents())->toBe([]);
});

it('merges the default config', function () {
    expect(config('watchtower-agent.features'))->toBe([
        'schedule' => true,
        'queues' => true,
        'workers' => true,
        'output' => true,
        'catchup' => true,
        'health' => true,
        'bindings' => false,
        'actions' => false,
        'events' => true,
        'broadcasts' => true,
        'cache_ops' => true,
        'mail' => true,
        'http' => true,
        'webhooks' => true,
        'trace_context' => true,
        'auth' => true,
        'shield' => false,
        'browser' => true,
    ])->and(config('watchtower-agent.max_output_bytes'))->toBe(65536)
        ->and(config('watchtower-agent.capture_all_task_output'))->toBeFalse()
        ->and(config('watchtower-agent.queues'))->toBeNull();
});
