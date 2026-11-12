<?php

use Cyberwizard\WatchtowerAgent\Host\CommandLine;
use Cyberwizard\WatchtowerAgent\Host\HostSnapshotter;
use Cyberwizard\WatchtowerAgent\Records\RecordFactory;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;

function hostFixture(string $path = ''): string
{
    return __DIR__.'/../Fixtures/host'.($path === '' ? '' : '/'.$path);
}

function fixtureSnapshotter(): HostSnapshotter
{
    $snapshotter = app(HostSnapshotter::class);
    $snapshotter->pause = function (): void {
        config(['watchtower-agent.host.proc_path' => hostFixture('proc-later')]);
    };

    return $snapshotter;
}

beforeEach(function () {
    $this->transport = $this->fakeTransport();

    config([
        'watchtower-agent.host.proc_path' => hostFixture('proc'),
        'watchtower-agent.host.passwd_path' => hostFixture('passwd'),
        'watchtower-agent.host.cgroup_path' => hostFixture('cgroup-v2'),
        'watchtower-agent.host.disks' => [__DIR__, __DIR__, '/definitely/not/a/path'],
        'watchtower-agent.host.sample_ms' => 0,
    ]);
});

it('parses a mounted host /proc', function () {
    $snapshot = fixtureSnapshotter()->snapshot();

    expect($snapshot)->toMatchArray([
        'v' => 1,
        'scope' => 'host',
        'os' => 'Linux',
        'hostname' => 'web-host-1',
        'uptime_seconds' => 12345,
    ])
        ->and($snapshot['cpu'])->toBe([
            'cores' => 2,
            'load_1' => 0.52,
            'load_5' => 0.40,
            'load_15' => 0.31,
            'usage_percent' => 50.0,
        ])
        ->and($snapshot['memory'])->toBe([
            'total_bytes' => 4194304000,
            'used_bytes' => 2097152000,
            'available_bytes' => 2097152000,
            'swap_total_bytes' => 1048576000,
            'swap_used_bytes' => 524288000,
            'container_limit_bytes' => 1073741824,
            'container_used_bytes' => 536870912,
        ])
        ->and($snapshot['processes']['total'])->toBe(5);
});

it('reports the top processes sorted by cpu with users, redacted commands and start times', function () {
    $top = fixtureSnapshotter()->snapshot()['processes']['top'];

    expect(array_column($top, 'pid'))->toBe([100, 200, 300, 1, 2])
        ->and($top[0])->toBe([
            'pid' => 100,
            'user' => 'www-data',
            'name' => 'php',
            'command' => 'php artisan queue:work --tries=3',
            'cpu_percent' => 60.0,
            'memory_bytes' => 104857600,
            'memory_percent' => 2.5,
            'started_at' => 1731405000.0,
        ])
        ->and($top[1])->toMatchArray([
            'user' => 'mysql',
            'name' => 'my sqld (x)',
            'command' => '/usr/sbin/mysqld --password=[redacted] --user=mysql',
            'cpu_percent' => 20.0,
            'memory_bytes' => 209715200,
            'memory_percent' => 5.0,
            'started_at' => 1731400003.0,
        ])
        ->and($top[2])->toMatchArray([
            'user' => null,
            'command' => 'redis-server *:6379 --requirepass [redacted]',
            'cpu_percent' => 1.0,
        ])
        ->and($top[3])->toMatchArray(['user' => 'root', 'command' => '/sbin/init', 'cpu_percent' => 0.0, 'memory_bytes' => 8192000])
        ->and($top[4])->toMatchArray(['name' => 'kthreadd', 'command' => '[kthreadd]', 'memory_bytes' => 0, 'memory_percent' => 0.0]);
});

it('dedupes disks on the same file system and skips missing paths', function () {
    $disks = fixtureSnapshotter()->snapshot()['disks'];

    expect($disks)->toHaveCount(1)
        ->and($disks[0]['mount'])->toBe(__DIR__)
        ->and($disks[0]['total_bytes'])->toBe($disks[0]['used_bytes'] + $disks[0]['free_bytes']);
});

it('reads cgroup v1 limits and ignores unlimited cgroups', function () {
    config(['watchtower-agent.host.cgroup_path' => hostFixture('cgroup-v1')]);

    expect(fixtureSnapshotter()->snapshot()['memory'])->toMatchArray([
        'container_limit_bytes' => 2147483648,
        'container_used_bytes' => 1048576000,
    ]);

    config(['watchtower-agent.host.cgroup_path' => hostFixture('cgroup-unlimited')]);

    expect(fixtureSnapshotter()->snapshot()['memory'])->toMatchArray([
        'container_limit_bytes' => null,
        'container_used_bytes' => null,
    ]);
});

it('leaves cpu usage null when both samples are identical', function () {
    $snapshot = app(HostSnapshotter::class)->snapshot();

    expect($snapshot['cpu']['usage_percent'])->toBeNull()
        ->and(array_column($snapshot['processes']['top'], 'cpu_percent'))->each->toBeNull();
});

it('reports the container scope for the default /proc, and host where there is no /proc', function () {
    config(['watchtower-agent.host.proc_path' => '/proc']);

    $snapshot = app(HostSnapshotter::class)->snapshot();

    expect($snapshot['scope'])->toBe(is_readable('/proc/meminfo') ? 'container' : 'host');
    Contract::assertRecord(app(RecordFactory::class)->make('wt-host', $snapshot), 'wt-host');
});

it('never throws when the proc root is unusable', function () {
    config([
        'watchtower-agent.host.proc_path' => hostFixture('missing'),
        'watchtower-agent.host.cgroup_path' => hostFixture('missing'),
        'watchtower-agent.host.passwd_path' => hostFixture('missing'),
    ]);

    $snapshotter = app(HostSnapshotter::class);
    $snapshotter->pause = fn () => throw new RuntimeException('boom');
    $snapshot = $snapshotter->snapshot();

    expect($snapshot['scope'])->toBe('host');
    Contract::assertRecord(app(RecordFactory::class)->make('wt-host', $snapshot), 'wt-host');
});

it('redacts secret-looking command arguments', function (string $command, string $expected) {
    expect(CommandLine::redact($command))->toBe($expected);
})->with([
    'password option' => ['artisan db:seed --password=hunter2', 'artisan db:seed --password=[redacted]'],
    'token option' => ['worker --token=abc.def --tries=3', 'worker --token=[redacted] --tries=3'],
    'space separated' => ['app --password hunter2 --verbose', 'app --password [redacted] --verbose'],
    'mysql -p' => ['mysql -uroot -psecret app', 'mysql -uroot -p[redacted] app'],
    'mysqldump path' => ['/usr/bin/mysqldump -h db -pS3cr3t! app', '/usr/bin/mysqldump -h db -p[redacted] app'],
    'non-mysql -p' => ['php artisan serve -p8000', 'php artisan serve -p8000'],
    'env key' => ['env APP_KEY=base64:xyz php artisan', 'env APP_KEY=[redacted] php artisan'],
    'env secret' => ['STRIPE_SECRET=sk_live_1 node app.js', 'STRIPE_SECRET=[redacted] node app.js'],
    'env token' => ['GITHUB_TOKEN=ghp_1 git fetch', 'GITHUB_TOKEN=[redacted] git fetch'],
    'env password' => ['DB_PASSWORD="a b" php', 'DB_PASSWORD=[redacted] php'],
    'url credentials' => ['redis-cli -u redis://default:p4ss@cache:6379/0', 'redis-cli -u redis://default:[redacted]@cache:6379/0'],
    'url credentials without user' => ['worker amqp://:p4ss@mq/vhost', 'worker amqp://:[redacted]@mq/vhost'],
    'harmless' => ['php artisan queue:work redis --queue=high,default --tries=3', 'php artisan queue:work redis --queue=high,default --tries=3'],
    'passive is not a password' => ['ftp --passive=1', 'ftp --passive=1'],
]);

it('caps commands at 300 characters and scrubs invalid UTF-8', function () {
    $command = CommandLine::redact('php '.str_repeat('a', 400)."\xff\x00x");

    expect(mb_strlen($command))->toBe(300)
        ->and(mb_check_encoding(CommandLine::redact("bad\xffbyte"), 'UTF-8'))->toBeTrue()
        ->and(CommandLine::redact("a\0b"))->toBe('a b');
});

it('sends wt-host from the command', function () {
    app()->instance(HostSnapshotter::class, fixtureSnapshotter());

    $this->artisan('watchtower:host-snapshot')->assertSuccessful();

    $records = $this->transport->ofType('wt-host');

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-host');
    expect($records[0]['scope'])->toBe('host')
        ->and($records[0]['server'])->toBe('web-1')
        ->and($records[0]['processes']['top'][0]['pid'])->toBe(100);
});

it('sends nothing when the host feature is off', function () {
    config(['watchtower-agent.features.host' => false]);

    $this->artisan('watchtower:host-snapshot')->assertSuccessful();

    expect($this->transport->ofType('wt-host'))->toBe([]);
});

it('snapshots this machine within the time budget', function () {
    config([
        'watchtower-agent.host.proc_path' => '/proc',
        'watchtower-agent.host.passwd_path' => '/etc/passwd',
        'watchtower-agent.host.cgroup_path' => '/sys/fs/cgroup',
        'watchtower-agent.host.disks' => ['/'],
        'watchtower-agent.host.sample_ms' => 500,
    ]);

    $started = microtime(true);
    $snapshot = app(HostSnapshotter::class)->snapshot();

    expect(microtime(true) - $started)->toBeLessThan(2.0);
    Contract::assertRecord(app(RecordFactory::class)->make('wt-host', $snapshot), 'wt-host');
});
