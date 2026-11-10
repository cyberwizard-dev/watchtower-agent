<?php

use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Records\RecordFactory;
use Cyberwizard\WatchtowerAgent\Transport;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Laravel\Nightwatch\Core;

it('binds the real transport by default', function () {
    expect(app(RecordTransport::class))->toBeInstanceOf(Transport::class);
});

it('sends records immediately through the Nightwatch ingest', function () {
    $sent = app(Transport::class)->send(['v' => 1, 't' => 'wt-test']);

    expect($sent)->toBeTrue()
        ->and($this->ingest->sentNow)->toBe([['v' => 1, 't' => 'wt-test']])
        ->and($this->ingest->written)->toBe([]);
});

it('buffers records with the current execution', function () {
    app(Transport::class)->buffer(['v' => 1, 't' => 'wt-test']);

    expect($this->ingest->written)->toBe([['v' => 1, 't' => 'wt-test']])
        ->and($this->ingest->sentNow)->toBe([]);
});

it('skips when Nightwatch is disabled', function () {
    app(Core::class)->config['enabled'] = false;

    expect(app(Transport::class)->send(['t' => 'wt-test']))->toBeFalse();
    app(Transport::class)->buffer(['t' => 'wt-test']);

    expect($this->ingest->sentNow)->toBe([])->and($this->ingest->written)->toBe([]);
});

it('skips when Nightwatch is paused', function () {
    app(Core::class)->pause();

    expect(app(Transport::class)->send(['t' => 'wt-test']))->toBeFalse()
        ->and($this->ingest->sentNow)->toBe([]);
});

it('skips when Nightwatch is not bound', function () {
    $this->app->offsetUnset(Core::class);

    expect((new Transport($this->app))->send(['t' => 'wt-test']))->toBeFalse();
});

it('returns false instead of throwing when the agent is unreachable', function () {
    $this->ingest->failWriteNow = true;

    expect(app(Transport::class)->send(['t' => 'wt-test']))->toBeFalse();
});

it('sends nothing when the agent master switch is off', function () {
    config(['watchtower-agent.enabled' => false]);

    expect(app(Recorder::class)->send('wt-test', []))->toBeFalse()
        ->and($this->ingest->sentNow)->toBe([]);
});

it('adds the common header to every record', function () {
    $record = app(RecordFactory::class)->make('wt-hello', ['foo' => 'bar']);

    expect($record)->toMatchArray([
        'v' => 1,
        't' => 'wt-hello',
        'deploy' => 'v1.2.3',
        'server' => 'web-1',
        'agent_version' => WatchtowerAgent::VERSION,
        'foo' => 'bar',
    ])->and($record['timestamp'])->toBeFloat()->toEqualWithDelta(microtime(true), 5);
});

it('falls back to the hostname and an empty deploy', function () {
    config(['nightwatch.deployment' => null, 'nightwatch.server' => null]);

    $record = app(RecordFactory::class)->make('wt-hello');

    expect($record['deploy'])->toBe('')->and($record['server'])->toBe((string) gethostname());
});
