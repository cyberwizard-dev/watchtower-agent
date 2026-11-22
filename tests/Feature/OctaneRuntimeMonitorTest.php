<?php

use Cyberwizard\WatchtowerAgent\Octane\OctaneRuntimeMonitor;

beforeEach(function () {
    config(['watchtower-agent.octane.snapshot_seconds' => 10]);
    $this->transport = $this->fakeTransport();
    $this->monitor = app(OctaneRuntimeMonitor::class);
});

it('reports worker lifecycle and memory telemetry', function () {
    $this->monitor->workerStarted(new stdClass);
    $this->monitor->requestReceived(new stdClass);
    usleep(1000);
    $lastReportedAt = new ReflectionProperty($this->monitor, 'lastReportedAt');
    $lastReportedAt->setValue($this->monitor, microtime(true) - 11);
    $this->monitor->requestHandled(new stdClass);
    $this->monitor->workerStopping(new stdClass);

    $records = $this->transport->ofType('wt-octane');

    expect(array_column($records, 'status'))->toBe(['started', 'running', 'stopped'])
        ->and($records[1]['requests'])->toBe(1)
        ->and($records[1]['memory_bytes'])->toBeGreaterThan(0)
        ->and($records[1]['last_request_duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($records[1]['worker_id'])->toContain((string) getmypid());
});

it('reports worker errors without exposing exception messages', function () {
    $this->monitor->workerStarted(new stdClass);
    $this->monitor->workerError((object) ['exception' => new RuntimeException('sensitive request data')]);

    $record = $this->transport->ofType('wt-octane')[1];

    expect($record['status'])->toBe('error')
        ->and($record['error_class'])->toBe(RuntimeException::class)
        ->and($record)->not->toHaveKey('message');
});

it('does not report when the Octane feature is disabled', function () {
    config(['watchtower-agent.features.octane' => false]);

    $this->monitor->workerStarted(new stdClass);

    expect($this->transport->sent)->toBe([]);
});
