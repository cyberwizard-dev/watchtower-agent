<?php

use Carbon\Carbon;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceDeferred;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCatchupCheckpoint;
use Cyberwizard\WatchtowerAgent\Catchup\CatchupReporter;
use Cyberwizard\WatchtowerAgent\Support\TaskGroup;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->transport = $this->fakeTransport();
    Date::setTestNow('2026-08-17 12:00:00');
});

afterEach(function () {
    Date::setTestNow();
});

function setCatchupTick(Carbon $at): void
{
    SchedulerCatchupCheckpoint::set('last_tick', $at->toDateTimeString());
}

dataset('catch-up sources', ['events', 'tables']);

it('reports replayed occurrences and the outage', function (string $source) {
    config(['watchtower-agent.catchup.source' => $source]);

    $task = app(Schedule::class)->call(fn () => null)->name('reports:nightly')->dailyAt('00:00')->replayOnCatchup();
    setCatchupTick(now()->subDays(2));

    $this->artisan('schedule-catchup:run', ['--only' => 'reports:nightly'])->assertSuccessful();

    $catchups = $this->transport->ofType('wt-catchup');

    expect($catchups)->toHaveCount(2);

    foreach ($catchups as $record) {
        Contract::assertRecord($record, 'wt-catchup');
        expect($record)->toMatchArray([
            'group' => TaskGroup::for($task),
            'name' => 'reports:nightly',
            'outcome' => 'replayed',
            'exit_code' => 0,
            'attempts' => 1,
            'error' => null,
        ]);
    }

    expect(array_column($catchups, 'scheduled_for'))->toEqualCanonicalizing([
        (float) Carbon::parse('2026-08-16 00:00:00')->getTimestamp(),
        (float) Carbon::parse('2026-08-17 00:00:00')->getTimestamp(),
    ]);

    if ($source === 'events') {
        expect($catchups[0]['duration'])->toBeInt();
    }

    $outages = $this->transport->ofType('wt-outage');
    expect($outages)->toHaveCount(1);
    Contract::assertRecord($outages[0], 'wt-outage');
    expect($outages[0])->toMatchArray([
        'started_at' => (float) now()->subDays(2)->getTimestamp(),
        'ended_at' => (float) now()->getTimestamp(),
        'minutes' => 2880,
    ]);
})->with('catch-up sources');

it('reports failed attempts and exhaustion', function (string $source) {
    config(['watchtower-agent.catchup.source' => $source, 'schedule-catchup.max_attempts' => 2]);

    app(Schedule::class)->call(fn () => throw new RuntimeException('boom'))->name('sync:crm')->dailyAt('11:57')->replayOnCatchup();
    setCatchupTick(now()->subMinutes(5));

    $this->artisan('schedule-catchup:run')->assertSuccessful();
    Date::setTestNow(now()->addMinutes(5));
    $this->artisan('schedule-catchup:run')->assertSuccessful();

    $catchups = $this->transport->ofType('wt-catchup');

    expect(array_column($catchups, 'outcome'))->toBe(['failed', 'exhausted'])
        ->and(array_column($catchups, 'attempts'))->toBe([1, 2])
        ->and($catchups[1]['error'])->toBe('boom')
        ->and($catchups[1]['exit_code'])->toBe($source === 'events' ? null : 1);

    foreach ($catchups as $record) {
        Contract::assertRecord($record, 'wt-catchup');
    }
})->with('catch-up sources');

it('uses the occurrence events when schedule-catchup provides them', function () {
    expect(CatchupReporter::usesOccurrenceEvents())->toBeTrue();

    config(['watchtower-agent.catchup.source' => 'tables']);
    expect(CatchupReporter::usesOccurrenceEvents())->toBeFalse();
});

it('does not resend table rows it has already reported', function () {
    config(['watchtower-agent.catchup.source' => 'tables']);

    app(Schedule::class)->call(fn () => null)->name('reports:nightly')->dailyAt('00:00')->replayOnCatchup();
    setCatchupTick(now()->subDays(2));

    $this->artisan('schedule-catchup:run', ['--only' => 'reports:nightly'])->assertSuccessful();
    expect($this->transport->ofType('wt-catchup'))->toHaveCount(2);

    app(CatchupReporter::class)->syncTables();
    app(CatchupReporter::class)->syncTables();

    expect($this->transport->ofType('wt-catchup'))->toHaveCount(2)
        ->and($this->transport->ofType('wt-outage'))->toHaveCount(1);
});

it('sends nothing when the catchup feature is off', function (string $source) {
    config(['watchtower-agent.catchup.source' => $source, 'watchtower-agent.features.catchup' => false]);

    app(Schedule::class)->call(fn () => null)->name('reports:nightly')->dailyAt('00:00')->replayOnCatchup();
    setCatchupTick(now()->subDays(2));

    $this->artisan('schedule-catchup:run')->assertSuccessful();

    expect($this->transport->ofType('wt-catchup'))->toBe([])
        ->and($this->transport->ofType('wt-outage'))->toBe([]);
})->with('catch-up sources');

it('reports deferred occurrences', function () {
    $task = app(Schedule::class)->command('inspire')->hourly()->replayOnCatchup();

    event(new ScheduleOccurrenceDeferred($task, now()->subHour()));

    $record = $this->transport->ofType('wt-catchup')[0];
    Contract::assertRecord($record, 'wt-catchup');

    expect($record)->toMatchArray([
        'group' => TaskGroup::for($task),
        'outcome' => 'deferred',
        'exit_code' => null,
        'attempts' => 0,
        'scheduled_for' => (float) now()->subHour()->getTimestamp(),
    ]);
});
