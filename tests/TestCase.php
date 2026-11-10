<?php

namespace Cyberwizard\WatchtowerAgent\Tests;

use Cyberwizard\ScheduleCatchup\Console\ScheduleCatchupServiceProvider;
use Cyberwizard\WatchtowerAgent\Catchup\CatchupReporter;
use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Output\OutputCapture;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Tests\Support\FakeIngest;
use Cyberwizard\WatchtowerAgent\Tests\Support\FakeTransport;
use Cyberwizard\WatchtowerAgent\WatchtowerAgentServiceProvider;
use Cyberwizard\WatchtowerAgent\Workers\WorkerHeartbeat;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithConsoleEvents;

    protected FakeIngest $ingest;

    protected function setUp(): void
    {
        parent::setUp();

        // Nightwatch's own sensors must never try to reach a real agent from the test suite.
        $this->ingest = new FakeIngest;
        $this->app->make(Core::class)->ingest = $this->ingest;
    }

    protected function getPackageProviders($app): array
    {
        return [
            NightwatchServiceProvider::class,
            ScheduleCatchupServiceProvider::class,
            WatchtowerAgentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('nightwatch.enabled', true);
        $app['config']->set('nightwatch.deployment', 'v1.2.3');
        $app['config']->set('nightwatch.server', 'web-1');
    }

    /**
     * Swap the real transport for one that keeps records in memory.
     */
    protected function fakeTransport(): FakeTransport
    {
        $fake = new FakeTransport;
        $this->app->instance(RecordTransport::class, $fake);
        $this->app->forgetInstance(Recorder::class);

        foreach ([
            WorkerHeartbeat::class,
            OutputCapture::class,
            CatchupReporter::class,
        ] as $singleton) {
            $this->app->forgetInstance($singleton);
        }

        return $fake;
    }
}
