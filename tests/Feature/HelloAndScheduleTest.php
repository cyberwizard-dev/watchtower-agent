<?php

use Cyberwizard\WatchtowerAgent\Support\TaskGroup;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Nightwatch\Core;

beforeEach(function () {
    $this->transport = $this->fakeTransport();
});

it('sends wt-hello and the schedule', function () {
    $this->artisan('watchtower:hello')->assertSuccessful();

    $hello = $this->transport->ofType('wt-hello');

    expect($hello)->toHaveCount(1);
    Contract::assertRecord($hello[0], 'wt-hello');
    expect($hello[0]['features'])->toBe([
        'schedule', 'queues', 'workers', 'output', 'catchup', 'health',
        'events', 'broadcasts', 'cache_ops', 'mail', 'http', 'webhooks', 'trace_context', 'auth',
        'notifications',
    ])
        ->and($hello[0]['laravel_version'])->toBe(app()->version())
        ->and($hello[0]['schedule_catchup_version'])->toBeString()
        ->and($this->transport->ofType('wt-schedule'))->toHaveCount(1);
});

it('lists actions as a feature only when enabled with a secret', function () {
    config(['watchtower-agent.features.actions' => true]);
    $this->artisan('watchtower:hello');
    expect($this->transport->ofType('wt-hello')[0]['features'])->not->toContain('actions');

    config(['watchtower-agent.actions.secret' => 'secret']);
    $this->artisan('watchtower:hello');
    expect($this->transport->ofType('wt-hello')[1]['features'])->toContain('actions');
});

it('never reports bindings as available', function () {
    config(['watchtower-agent.features.bindings' => true]);

    $this->artisan('watchtower:hello');

    expect($this->transport->ofType('wt-hello')[0]['features'])->not->toContain('bindings');
});

it('sends every scheduled event in wt-schedule', function () {
    $schedule = app(Schedule::class);
    $schedule->command('inspire')->dailyAt('03:00')->timezone('Europe/Berlin')->withoutOverlapping()->onOneServer()->description('Be inspired')->replayOnCatchup();
    $schedule->call(fn () => null)->everyTenSeconds();
    $schedule->exec('echo hi')->hourly()->runInBackground()->evenInMaintenanceMode();

    $this->artisan('watchtower:schedule-sync')->assertSuccessful();

    $record = $this->transport->ofType('wt-schedule')[0];
    Contract::assertRecord($record, 'wt-schedule');

    $tasks = collect($record['tasks'])->keyBy('name');
    $inspire = $tasks->first(fn (array $task) => str_ends_with($task['name'], 'inspire'));

    expect($inspire)->toMatchArray([
        'cron' => '0 3 * * *',
        'timezone' => 'Europe/Berlin',
        'repeat_seconds' => 0,
        'without_overlapping' => true,
        'on_one_server' => true,
        'run_in_background' => false,
        'description' => 'Be inspired',
        'replay_on_catchup' => true,
    ])->and($inspire['next_run_at'])->toBeFloat()->toBeGreaterThan(microtime(true));

    $closure = $tasks->first(fn (array $task) => str_starts_with($task['name'], 'Closure at: '));
    expect($closure['repeat_seconds'])->toBe(10)->and($closure['replay_on_catchup'])->toBeFalse();

    $exec = $tasks->get('echo hi');
    expect($exec['run_in_background'])->toBeTrue()->and($exec['even_in_maintenance_mode'])->toBeTrue();

    // The agent schedules its own commands, which are part of the registry too.
    expect($tasks->keys()->contains(fn (string $name) => str_contains($name, 'watchtower:health-report')))->toBeTrue();
});

it('computes the same group hash as Nightwatch', function (Closure $define) {
    $task = $define(app(Schedule::class));

    app(Core::class)->scheduledTask(new ScheduledTaskFinished($task, 0.5));

    $nightwatch = collect($this->ingest->written)->firstWhere('t', 'scheduled-task');

    expect($nightwatch)->not->toBeNull()
        ->and(TaskGroup::for($task))->toBe($nightwatch['_group'])
        ->and(TaskGroup::name($task))->toBe($nightwatch['name']);
})->with([
    'artisan command' => [fn (Schedule $s) => $s->command('inspire --quiet')->everyFiveMinutes()],
    'command with timezone' => [fn (Schedule $s) => $s->command('cache:clear')->dailyAt('01:00')->timezone('America/New_York')],
    'sub-minute command' => [fn (Schedule $s) => $s->command('inspire')->everyFifteenSeconds()],
    'closure' => [fn (Schedule $s) => $s->call(fn () => null)->hourly()],
    'named closure' => [fn (Schedule $s) => $s->call(fn () => null)->name('reports:build')->daily()],
    'exec' => [fn (Schedule $s) => $s->exec('ls -la')->weekly()],
    'job' => [fn (Schedule $s) => $s->job(new class {})->everyMinute()],
]);

it('hashes command groups like Nightwatch', function () {
    expect(TaskGroup::command('queue:retry'))->toBe(hash('xxh128', 'queue:retry'));
});
