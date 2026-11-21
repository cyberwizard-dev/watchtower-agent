<?php

use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;

beforeEach(function () {
    $this->transport = $this->fakeTransport();
});

it('sends a custom metric with the common header', function () {
    WatchtowerAgent::metric('orders.processed', 42, ['region' => 'eu', 'queue' => 'default']);

    $records = $this->transport->ofType('wt-metric');

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-metric');

    expect($records[0]['name'])->toBe('orders.processed')
        ->and($records[0]['value'])->toBe(42.0)
        ->and($records[0]['tags'])->toBe(['region' => 'eu', 'queue' => 'default'])
        ->and($records[0]['server'])->toBe('web-1')
        ->and($records[0]['deploy'])->toBe('v1.2.3')
        ->and($records[0]['agent_version'])->toBe(WatchtowerAgent::VERSION);
});

it('accepts ints and numeric strings, and omits empty tags', function () {
    WatchtowerAgent::metric('queue.lag', 3);
    WatchtowerAgent::metric('queue.lag', '4.5');

    $records = $this->transport->ofType('wt-metric');

    expect($records)->toHaveCount(2)
        ->and($records[0]['value'])->toBe(3.0)
        ->and($records[0]['tags'])->toBeNull()
        ->and($records[1]['value'])->toBe(4.5);
});

it('ignores invalid names and non-finite values', function () {
    foreach (['has spaces', '', str_repeat('a', 121), '-starts-with-dash'] as $name) {
        WatchtowerAgent::metric($name, 1);
    }

    WatchtowerAgent::metric('valid.metric', 'not-a-number');
    WatchtowerAgent::metric('valid.metric', NAN);
    WatchtowerAgent::metric('valid.metric', INF);

    expect($this->transport->ofType('wt-metric'))->toBe([]);
});

it('keeps only valid scalar tags, capped and truncated', function () {
    $tags = ['region' => 'eu', 'shard' => 3, 'retry' => true, 'bad key' => 'x', 'list' => ['a']];
    $tags['long'] = str_repeat('x', 150);

    for ($i = 0; $i < 30; $i++) {
        $tags["tag{$i}"] = 'value';
    }

    WatchtowerAgent::metric('jobs.failed', 2, $tags);

    $tags = $this->transport->ofType('wt-metric')[0]['tags'];

    expect($tags)->not->toHaveKey('bad key')
        ->not->toHaveKey('list')
        ->and($tags['region'])->toBe('eu')
        ->and($tags['shard'])->toBe('3')
        ->and($tags['retry'])->toBe('true')
        ->and($tags)->toHaveCount(20)
        ->and(mb_strlen($tags['long']))->toBe(100);
});

it('sends nothing when the feature or the agent is disabled', function () {
    config(['watchtower-agent.features.metrics' => false]);
    WatchtowerAgent::metric('orders.processed', 1);

    config(['watchtower-agent.features.metrics' => true, 'watchtower-agent.enabled' => false]);
    WatchtowerAgent::metric('orders.processed', 1);

    expect($this->transport->ofType('wt-metric'))->toBe([]);
});

it('never throws when the transport fails', function () {
    $this->app->instance(RecordTransport::class, new class implements RecordTransport
    {
        public function send(array $record): bool
        {
            throw new RuntimeException('send broke');
        }

        public function buffer(array $record): void {}

        public function execution(): ?array
        {
            return null;
        }

        public function sample(): void {}
    });
    $this->app->forgetInstance(Recorder::class);

    WatchtowerAgent::metric('orders.processed', 42);

    expect(true)->toBeTrue();
});

it('lists metrics as a hello feature', function () {
    expect(WatchtowerAgent::features())->toContain('metrics');
});
