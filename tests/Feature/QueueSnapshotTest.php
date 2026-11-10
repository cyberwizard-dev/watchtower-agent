<?php

use Cyberwizard\WatchtowerAgent\Queue\QueueTargets;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Tests\Support\QueueTables;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->transport = $this->fakeTransport();
    QueueTables::create();
});

it('snapshots the default connection and queue by default', function () {
    config(['queue.default' => 'database']);

    expect(app(QueueTargets::class)->all())->toBe([['connection' => 'database', 'queue' => 'default']]);
});

it('parses configured connection:queue pairs', function () {
    config(['watchtower-agent.queues' => ['database:emails', 'database', 'redis:high', 'database:emails']]);

    expect(app(QueueTargets::class)->all())->toBe([
        ['connection' => 'database', 'queue' => 'emails'],
        ['connection' => 'database', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'high'],
    ]);
});

it('sends real queue depth', function () {
    config(['watchtower-agent.queues' => ['database:default', 'database:emails']]);

    $queue = Queue::connection('database');
    $queue->pushRaw('{"job":"a"}', 'default');
    $queue->pushRaw('{"job":"b"}', 'default');
    $queue->laterOn('default', 600, 'App\\Jobs\\Later');
    $queue->pushRaw('{"job":"c"}', 'emails');

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
    ]);

    $this->artisan('watchtower:queue-snapshot')->assertSuccessful();

    $record = $this->transport->ofType('wt-queue-snapshot')[0];
    Contract::assertRecord($record, 'wt-queue-snapshot');

    expect($record['failed_jobs'])->toBe(1)
        ->and($record['queues'][0])->toMatchArray([
            'connection' => 'database',
            'queue' => 'default',
            'size' => 3,
            'pending' => 2,
            'delayed' => 1,
            'reserved' => 0,
        ])
        ->and($record['queues'][0]['oldest_pending_at'])->toBeFloat()
        ->and($record['queues'][1])->toMatchArray(['queue' => 'emails', 'size' => 1]);
});

it('reports null measures for an unreachable connection', function () {
    config(['watchtower-agent.queues' => ['missing:default']]);

    $this->artisan('watchtower:queue-snapshot')->assertSuccessful();

    $record = $this->transport->ofType('wt-queue-snapshot')[0];
    Contract::assertRecord($record, 'wt-queue-snapshot');

    expect($record['queues'][0])->toBe([
        'connection' => 'missing', 'queue' => 'default', 'size' => null, 'pending' => null,
        'delayed' => null, 'reserved' => null, 'oldest_pending_at' => null,
    ]);
});
