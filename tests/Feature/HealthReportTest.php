<?php

use Cyberwizard\WatchtowerAgent\Health\BuiltInChecks;
use Cyberwizard\WatchtowerAgent\Health\CheckResult;
use Cyberwizard\WatchtowerAgent\Health\HealthReporter;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Tests\Support\QueueTables;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;

beforeEach(function () {
    $this->transport = $this->fakeTransport();
    QueueTables::create();
});

it('sends the built-in checks in wt-health', function () {
    $this->artisan('watchtower:health-report')->assertSuccessful();

    $record = $this->transport->ofType('wt-health')[0];
    Contract::assertRecord($record, 'wt-health');

    $checks = collect($record['checks'])->keyBy('name');

    expect($checks->keys()->all())->toBe([
        'database:testing',
        'cache:array',
        'disk:'.storage_path(),
        'queue:sync/default',
        'failed-jobs',
    ]);

    expect($checks['database:testing'])->toMatchArray(['status' => 'healthy', 'unit' => 'ms'])
        ->and($checks['cache:array']['status'])->toBe('healthy')
        ->and($checks['disk:'.storage_path()]['unit'])->toBe('%')
        ->and($checks['disk:'.storage_path()]['value'])->toBeFloat()
        ->and($checks['queue:sync/default'])->toMatchArray(['status' => 'healthy', 'value' => 0.0, 'unit' => 'jobs'])
        ->and($checks['failed-jobs'])->toMatchArray(['status' => 'healthy', 'value' => 0.0]);
});

it('runs registered application checks', function () {
    WatchtowerAgent::check('payments-api', fn () => CheckResult::degraded('Slow responses', 1450, 'ms'));
    WatchtowerAgent::check('search', fn () => true);
    WatchtowerAgent::check('broken', fn () => throw new RuntimeException('Connection refused'));
    WatchtowerAgent::check('odd', fn () => 'yes');

    $this->artisan('watchtower:health-report')->assertSuccessful();

    $record = $this->transport->ofType('wt-health')[0];
    Contract::assertRecord($record, 'wt-health');
    $checks = collect($record['checks'])->keyBy('name');

    expect($checks['payments-api'])->toMatchArray(['status' => 'degraded', 'value' => 1450.0, 'unit' => 'ms', 'message' => 'Slow responses'])
        ->and($checks['search'])->toMatchArray(['status' => 'healthy', 'message' => 'OK'])
        ->and($checks['broken'])->toMatchArray(['status' => 'unhealthy', 'message' => 'Connection refused'])
        ->and($checks['odd']['status'])->toBe('unhealthy')
        ->and($checks['broken']['duration_ms'])->toBeInt();
});

it('marks an unreachable database connection unhealthy', function () {
    config([
        'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite'],
        'watchtower-agent.health.database_connections' => ['broken'],
    ]);

    $checks = collect(app(HealthReporter::class)->run())->keyBy('name');

    expect($checks['database:broken']['status'])->toBe('unhealthy');
});

it('detects a redis connection only when redis is in use', function () {
    $names = fn () => array_keys(app(BuiltInChecks::class)->all());

    expect($names())->not->toContain('redis:default');

    config(['cache.stores.redis' => ['driver' => 'redis'], 'cache.default' => 'redis', 'database.redis.default' => ['host' => '127.0.0.1']]);
    expect($names())->toContain('redis:default');

    config(['watchtower-agent.health.redis_connections' => []]);
    expect($names())->not->toContain('redis:default');
});

it('grades disk space against the thresholds', function () {
    $checks = app(BuiltInChecks::class);

    config(['watchtower-agent.health.disk_degraded_below_percent' => 101, 'watchtower-agent.health.disk_unhealthy_below_percent' => 0]);
    expect($checks->disk(sys_get_temp_dir())->status)->toBe('degraded');

    config(['watchtower-agent.health.disk_unhealthy_below_percent' => 101]);
    expect($checks->disk(sys_get_temp_dir())->status)->toBe('unhealthy');

    expect($checks->disk('/definitely/not/a/path')->status)->toBe('unhealthy');
});

it('degrades the failed jobs check above the configured threshold', function () {
    config(['watchtower-agent.health.failed_jobs_degraded_above' => 0]);
    DB::table('failed_jobs')->insert([
        'uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
    ]);

    expect(app(BuiltInChecks::class)->failedJobs())->toMatchObject(['status' => 'degraded', 'value' => 1.0]);
});

it('builds check results with the static constructors', function () {
    expect(CheckResult::healthy()->status)->toBe('healthy')
        ->and(CheckResult::degraded('slow', 2)->value)->toBe(2.0)
        ->and(CheckResult::unhealthy('down')->toRecord('x', 5))->toBe([
            'name' => 'x', 'status' => 'unhealthy', 'value' => null, 'unit' => null, 'message' => 'down', 'duration_ms' => 5,
        ]);
});
