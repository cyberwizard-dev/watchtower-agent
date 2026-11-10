<?php

use App\Broadcasting\FailingBroadcaster;
use App\Events\BrokenBroadcast;
use App\Events\PodcastPublished;
use App\Events\PodcastPublishedNow;
use Cyberwizard\WatchtowerAgent\Broadcasting\BroadcastRecorder;
use Cyberwizard\WatchtowerAgent\Broadcasting\InstrumentedBroadcaster;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Tests\Support\QueueTables;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Context;

beforeEach(function () {
    config([
        'broadcasting.default' => 'log',
        'broadcasting.connections.log' => ['driver' => 'log'],
        'broadcasting.connections.null' => ['driver' => 'null'],
        'broadcasting.connections.flaky' => ['driver' => 'flaky'],
    ]);
    app()->forgetInstance(BroadcastManager::class);
    Broadcast::clearResolvedInstance(BroadcastManager::class);

    $this->transport = $this->fakeTransport();
});

function broadcasts(object $test): array
{
    return $test->transport->bufferedOfType('wt-broadcast');
}

it('decorates broadcast connections and forwards everything else', function () {
    $connection = Broadcast::connection('log');

    expect($connection)->toBeInstanceOf(InstrumentedBroadcaster::class)
        ->and($connection->driver)->toBe('log')
        ->and($connection->connection)->toBe('log')
        ->and($connection->channel('orders.{id}', fn () => true))->toBe($connection)
        ->and($connection->getChannels()->keys()->all())->toBe(['orders.{id}']);
});

it('records a ShouldBroadcastNow event as sent, linked to its wt-event', function () {
    event(new PodcastPublishedNow);

    $event = $this->transport->bufferedOfType('wt-event')[0];
    $records = broadcasts($this);

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-broadcast');

    expect($event['broadcast'])->toBeTrue()
        ->and($records[0])->toMatchArray([
            'event_id' => $event['event_id'],
            'name' => 'podcast.published',
            'class' => PodcastPublishedNow::class,
            'connection' => 'log',
            'driver' => 'log',
            'channels' => [
                ['name' => 'podcasts', 'type' => 'public'],
                ['name' => 'private-user.1', 'type' => 'private'],
                ['name' => 'presence-studio', 'type' => 'presence'],
            ],
            'queued' => false,
            'job_id' => null,
            'stage' => 'sent',
            'exception' => null,
            'socket_excluded' => false,
            'truncated' => false,
        ])
        ->and($records[0]['duration'])->toBeInt()
        ->and(json_decode($records[0]['payload'], true))->toBe(['podcastId' => 7, 'apiToken' => '[redacted]']);
});

it('flags broadcasts that exclude the current socket', function () {
    $event = new PodcastPublishedNow;
    $event->socket = '1234.5678';

    event($event);

    expect(broadcasts($this)[0]['socket_excluded'])->toBeTrue()
        ->and(json_decode(broadcasts($this)[0]['payload'], true))->not->toHaveKey('socket');
});

it('records a driver failure and rethrows it', function () {
    Broadcast::extend('flaky', fn () => new FailingBroadcaster);
    // What the provider does once the app has booted, for drivers registered in boot().
    app(BroadcastRecorder::class)->install(app(BroadcastManager::class));
    config(['broadcasting.default' => 'flaky']);

    expect(fn () => event(new PodcastPublishedNow))->toThrow(BroadcastException::class);

    expect(broadcasts($this)[0])->toMatchArray([
        'connection' => 'flaky',
        'driver' => 'flaky',
        'stage' => 'failed',
        'exception' => 'Illuminate\Broadcasting\BroadcastException: Pusher error: 503 Service Unavailable.',
    ]);
});

it('follows a queued broadcast from queued to sent under one broadcast id', function () {
    QueueTables::create();
    config(['queue.default' => 'database']);

    event(new PodcastPublished);

    $eventId = $this->transport->bufferedOfType('wt-event')[0]['event_id'];
    $queued = broadcasts($this);

    expect($queued)->toHaveCount(1);
    Contract::assertRecord($queued[0], 'wt-broadcast');
    expect($queued[0])->toMatchArray([
        'stage' => 'queued',
        'queued' => true,
        'event_id' => $eventId,
        'class' => PodcastPublished::class,
        'duration' => null,
    ])->and($queued[0]['job_id'])->toBeString();

    Context::flush();
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);

    $sent = broadcasts($this)[1];

    Contract::assertRecord($sent, 'wt-broadcast');
    expect($sent)->toMatchArray([
        'broadcast_id' => $queued[0]['broadcast_id'],
        'event_id' => $eventId,
        'job_id' => $queued[0]['job_id'],
        'class' => PodcastPublished::class,
        'name' => 'podcast.published',
        'stage' => 'sent',
        'queued' => true,
    ]);
});

it('records a queued broadcast whose job fails before reaching a driver', function () {
    QueueTables::create();
    config(['queue.default' => 'database']);

    event(new BrokenBroadcast);
    Context::flush();
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));

    [$queued, $failed] = broadcasts($this);

    Contract::assertRecord($failed, 'wt-broadcast');
    expect($queued['channels'])->toBe([])
        ->and($failed)->toMatchArray([
            'broadcast_id' => $queued['broadcast_id'],
            'stage' => 'failed',
            'class' => BrokenBroadcast::class,
            'exception' => 'RuntimeException: No channels today',
        ]);
});

it('ignores the null driver by default', function () {
    config(['broadcasting.default' => 'null']);

    event(new PodcastPublishedNow);

    expect(broadcasts($this))->toBe([]);
});

it('does nothing when the feature is off', function () {
    config(['watchtower-agent.features.broadcasts' => false]);

    event(new PodcastPublishedNow);

    expect(broadcasts($this))->toBe([]);
});
