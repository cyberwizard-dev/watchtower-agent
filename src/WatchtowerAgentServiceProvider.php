<?php

namespace Cyberwizard\WatchtowerAgent;

use Cyberwizard\ScheduleCatchup\Events\ScheduleCatchupCompleted;
use Cyberwizard\ScheduleCatchup\Events\ScheduleCatchupFailed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceDeferred;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceFailed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOccurrenceReplayed;
use Cyberwizard\ScheduleCatchup\Events\ScheduleOutageRecorded;
use Cyberwizard\WatchtowerAgent\Auth\AuthRecorder;
use Cyberwizard\WatchtowerAgent\Broadcasting\BroadcastRecorder;
use Cyberwizard\WatchtowerAgent\Cache\CacheOpRecorder;
use Cyberwizard\WatchtowerAgent\Catchup\CatchupReporter;
use Cyberwizard\WatchtowerAgent\Console\ActionsCommand;
use Cyberwizard\WatchtowerAgent\Console\HealthReportCommand;
use Cyberwizard\WatchtowerAgent\Console\HelloCommand;
use Cyberwizard\WatchtowerAgent\Console\QueueSnapshotCommand;
use Cyberwizard\WatchtowerAgent\Console\ScheduleSyncCommand;
use Cyberwizard\WatchtowerAgent\Console\ShieldSyncCommand;
use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Events\EventRecorder;
use Cyberwizard\WatchtowerAgent\Events\InstrumentedDispatcher;
use Cyberwizard\WatchtowerAgent\Health\HealthCheckRegistry;
use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Http\Middleware\RecordTraceparent;
use Cyberwizard\WatchtowerAgent\Mail\MailRecorder;
use Cyberwizard\WatchtowerAgent\Output\OutputCapture;
use Cyberwizard\WatchtowerAgent\Queue\JobLifecycle;
use Cyberwizard\WatchtowerAgent\Queue\QueuePayload;
use Cyberwizard\WatchtowerAgent\Records\ExecutionLimiter;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Records\RecordFactory;
use Cyberwizard\WatchtowerAgent\Records\RecordThrottle;
use Cyberwizard\WatchtowerAgent\Shield\Evaluator;
use Cyberwizard\WatchtowerAgent\Shield\Honeypot;
use Cyberwizard\WatchtowerAgent\Shield\PassCookie;
use Cyberwizard\WatchtowerAgent\Shield\RulesRepository;
use Cyberwizard\WatchtowerAgent\Shield\ShieldMiddleware;
use Cyberwizard\WatchtowerAgent\Shield\ShieldRecorder;
use Cyberwizard\WatchtowerAgent\Shield\VerifyChallengeController;
use Cyberwizard\WatchtowerAgent\Webhooks\RecordIncomingWebhook;
use Cyberwizard\WatchtowerAgent\Webhooks\SpatieWebhookRecorder;
use Cyberwizard\WatchtowerAgent\Workers\WorkerHeartbeat;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

class WatchtowerAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchtower-agent.php', 'watchtower-agent');

        $this->app->singleton(RecordTransport::class, Transport::class);
        $this->app->singleton(RecordFactory::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(HealthCheckRegistry::class);
        $this->app->singleton(WorkerHeartbeat::class);
        $this->app->singleton(OutputCapture::class);
        $this->app->singleton(CatchupReporter::class);
        $this->app->singleton(ExecutionLimiter::class);
        $this->app->singleton(RecordThrottle::class);
        $this->app->singleton(EventRecorder::class);
        $this->app->singleton(JobLifecycle::class);
        $this->app->singleton(BroadcastRecorder::class);
        $this->app->singleton(MailRecorder::class);
        $this->app->singleton(CacheOpRecorder::class);
        $this->app->singleton(HttpRecorder::class);
        $this->app->singleton(AuthRecorder::class);
        $this->app->singleton(RulesRepository::class);
        $this->app->singleton(Evaluator::class);
        $this->app->singleton(ShieldRecorder::class);
        $this->app->singleton(PassCookie::class);

        $this->instrumentEventDispatcher();

        $this->callAfterResolving(BroadcastManager::class, function (BroadcastManager $manager): void {
            if (WatchtowerAgent::feature('broadcasts')) {
                $this->app->make(BroadcastRecorder::class)->install($manager);
            }
        });

        // Again after boot, for drivers the app registered with Broadcast::extend().
        $this->app->booted(function ($app): void {
            if (WatchtowerAgent::feature('broadcasts') && $app->resolved(BroadcastManager::class)) {
                $app->make(BroadcastRecorder::class)->install($app->make(BroadcastManager::class));
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/watchtower-agent.php' => config_path('watchtower-agent.php'),
        ], 'watchtower-agent-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                HelloCommand::class,
                ScheduleSyncCommand::class,
                QueueSnapshotCommand::class,
                HealthReportCommand::class,
                ActionsCommand::class,
                ShieldSyncCommand::class,
            ]);
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'watchtower-agent');
        $this->registerShield();

        $this->registerListeners($this->app->make(Dispatcher::class));
        $this->registerObservability($this->app->make(Dispatcher::class));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->scheduleCommands($schedule);
        });
    }

    /**
     * Listeners are always registered; each one checks its feature flag when it runs.
     */
    private function registerListeners(Dispatcher $events): void
    {
        if (class_exists(WorkerStarting::class)) {
            $events->listen(WorkerStarting::class, [WorkerHeartbeat::class, 'starting']);
        }

        $events->listen(Looping::class, [WorkerHeartbeat::class, 'looping']);
        $events->listen(WorkerStopping::class, [WorkerHeartbeat::class, 'stopping']);
        $events->listen([JobProcessed::class, JobExceptionOccurred::class], [WorkerHeartbeat::class, 'jobFinished']);

        $events->listen(ScheduledTaskStarting::class, [OutputCapture::class, 'taskStarting']);
        $events->listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], [OutputCapture::class, 'taskFinished']);
        $events->listen(ScheduledBackgroundTaskFinished::class, [OutputCapture::class, 'backgroundTaskFinished']);
        $events->listen(CommandStarting::class, [OutputCapture::class, 'commandStarting']);
        $events->listen(CommandFinished::class, [OutputCapture::class, 'commandFinished']);

        if (class_exists(ScheduleOccurrenceReplayed::class)) {
            $events->listen(ScheduleOccurrenceReplayed::class, [CatchupReporter::class, 'replayed']);
            $events->listen(ScheduleOccurrenceFailed::class, [CatchupReporter::class, 'failed']);
            $events->listen(ScheduleOccurrenceDeferred::class, [CatchupReporter::class, 'deferred']);
            $events->listen(ScheduleOutageRecorded::class, [CatchupReporter::class, 'outage']);
        }

        if (class_exists(ScheduleCatchupCompleted::class)) {
            $events->listen([ScheduleCatchupCompleted::class, ScheduleCatchupFailed::class], [CatchupReporter::class, 'sync']);
            $events->listen(CommandFinished::class, [CatchupReporter::class, 'sync']);
        }
    }

    /**
     * Replace the event dispatcher with InstrumentedDispatcher (same state, timed listeners).
     * Done in register() so listeners and dispatches from the rest of the app go through it.
     */
    private function instrumentEventDispatcher(): void
    {
        if (! WatchtowerAgent::feature('events') || ! config('watchtower-agent.events.listener_timing', true)) {
            return;
        }

        $this->app->extend('events', function (mixed $events, $app): mixed {
            $recorder = $app->make(EventRecorder::class);
            $instrumented = InstrumentedDispatcher::wrap($events, $recorder);

            if ($instrumented === null) {
                return $events;
            }

            $recorder->mode = 'instrumented';

            return $instrumented;
        });

        Facade::clearResolvedInstance('events');
    }

    /**
     * Events, broadcasts, cache operations, mail, outgoing HTTP, webhooks, trace context and auth.
     * Every hook checks its feature flag when it runs; none of them can throw into the app.
     */
    private function registerObservability(Dispatcher $events): void
    {
        $recorder = $this->app->make(EventRecorder::class);

        if (WatchtowerAgent::feature('events') && $recorder->mode !== 'instrumented') {
            $events->listen('*', static function (string $name, array $payload = []): void {
                app(EventRecorder::class)->dispatched($name, $payload);
            });
        }

        // Per-execution summaries are flushed before Nightwatch digests the execution.
        $flush = static function (): void {
            app(EventRecorder::class)->flushSummary();
        };
        $this->app->terminating($flush);
        $events->listen([CommandFinished::class, ScheduledTaskFinished::class, ScheduledTaskFailed::class], $flush);

        $events->listen(JobProcessing::class, [JobLifecycle::class, 'processing']);
        $events->listen(JobExceptionOccurred::class, [JobLifecycle::class, 'exceptionOccurred']);
        $events->listen([JobProcessed::class, JobFailed::class, JobReleasedAfterException::class], [JobLifecycle::class, 'finished']);
        $events->listen(JobQueued::class, [BroadcastRecorder::class, 'queued']);
        $events->listen(JobQueued::class, [MailRecorder::class, 'queued']);
        $events->listen(MessageSending::class, [MailRecorder::class, 'sending']);
        $events->listen(MessageSent::class, [MailRecorder::class, 'sent']);

        // illuminate/auth is not a dependency; listen only to the events that exist.
        foreach (array_keys(AuthRecorder::EVENTS) as $event) {
            if (class_exists($event)) {
                $events->listen($event, [AuthRecorder::class, 'handle']);
            }
        }

        QueuePayload::register();

        foreach ([
            'Illuminate\Cache\Events\CacheFlushing' => 'starting',
            'Illuminate\Cache\Events\CacheFlushed' => 'flushed',
            'Illuminate\Cache\Events\CacheFlushFailed' => 'flushFailed',
            'Illuminate\Cache\Events\CacheLocksFlushing' => 'starting',
            'Illuminate\Cache\Events\CacheLocksFlushed' => 'locksFlushed',
            'Illuminate\Cache\Events\CacheLocksFlushFailed' => 'locksFlushFailed',
            'Illuminate\Cache\Events\CacheFailedOver' => 'failedOver',
        ] as $event => $method) {
            // These events exist from Laravel 12.x / 13.x only.
            if (class_exists($event)) {
                $events->listen($event, [CacheOpRecorder::class, $method]);
            }
        }

        if (WatchtowerAgent::feature('cache_ops')) {
            CacheOpRecorder::installFlushMacro();
        }

        if (config('watchtower-agent.cache_ops.locks', false)) {
            CacheOpRecorder::installLockMacros();
        }

        if (class_exists(SpatieWebhookRecorder::SUCCEEDED)) {
            $events->listen([SpatieWebhookRecorder::SUCCEEDED, SpatieWebhookRecorder::FAILED], [SpatieWebhookRecorder::class, 'handle']);
        }

        $this->callAfterResolving(HttpFactory::class, function (HttpFactory $factory): void {
            $factory->globalMiddleware($this->app->make(HttpRecorder::class));
        });

        if (! PendingRequest::hasMacro('withWatchtowerWebhook')) {
            PendingRequest::macro('withWatchtowerWebhook', function (string $event, string $destination) {
                /** @var PendingRequest $this */
                return $this->withOptions([HttpRecorder::OPTION_WEBHOOK => ['event' => $event, 'destination' => $destination]]);
            });
        }

        $this->callAfterResolving('router', function ($router): void {
            if (method_exists($router, 'aliasMiddleware')) {
                $router->aliasMiddleware('watchtower.webhook', RecordIncomingWebhook::class);
            }
        });

        $this->callAfterResolving(HttpKernel::class, function ($kernel): void {
            if (method_exists($kernel, 'hasMiddleware') && method_exists($kernel, 'pushMiddleware')
                && ! $kernel->hasMiddleware(RecordTraceparent::class)) {
                $kernel->pushMiddleware(RecordTraceparent::class);
            }
        });
    }

    /**
     * Shield: the global middleware, the challenge verify route and @watchtowerHoneypot. All
     * three are installed even when the feature is off and check the flag when they run.
     */
    private function registerShield(): void
    {
        $this->callAfterResolving(HttpKernel::class, function ($kernel): void {
            self::installShieldMiddleware($kernel);
        });

        if (! ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            $this->callAfterResolving('router', function ($router): void {
                if (! $router->has('watchtower.shield.verify')) {
                    $router->post(ShieldMiddleware::VERIFY_PATH, VerifyChallengeController::class)->name('watchtower.shield.verify');
                }
            });
        }

        $this->callAfterResolving('blade.compiler', function ($blade): void {
            $blade->directive('watchtowerHoneypot', static fn (): string => '<?php echo \\'.Honeypot::class.'::render(); ?>');
        });
    }

    /**
     * Shield runs before the rest of the global stack, but after TrustProxies (so
     * $request->ip() is the client, not the load balancer) and after Nightwatch's own global
     * middleware (so the request is already being captured and sampled).
     */
    public static function installShieldMiddleware(mixed $kernel): void
    {
        if (! method_exists($kernel, 'getGlobalMiddleware') || ! method_exists($kernel, 'setGlobalMiddleware')) {
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(ShieldMiddleware::class);
            }

            return;
        }

        $middleware = array_values(array_filter(
            $kernel->getGlobalMiddleware(),
            static fn (mixed $entry): bool => $entry !== ShieldMiddleware::class,
        ));

        $position = 0;

        foreach ($middleware as $index => $entry) {
            $class = is_string($entry) ? explode(':', $entry, 2)[0] : null;

            if ($class !== null && (is_a($class, TrustProxies::class, true) || $class === 'Laravel\Nightwatch\Hooks\GlobalMiddleware')) {
                $position = $index + 1;
            }
        }

        array_splice($middleware, $position, 0, [ShieldMiddleware::class]);

        $kernel->setGlobalMiddleware($middleware);
    }

    private function scheduleCommands(Schedule $schedule): void
    {
        if (! WatchtowerAgent::enabled() || ! config('watchtower-agent.schedule_commands', true)) {
            return;
        }

        $schedule->command(HelloCommand::class)->hourly()->withoutOverlapping();

        if (WatchtowerAgent::feature('schedule')) {
            $schedule->command(ScheduleSyncCommand::class)->everyTenMinutes()->withoutOverlapping();
        }

        if (WatchtowerAgent::feature('queues')) {
            $schedule->command(QueueSnapshotCommand::class)->everyMinute()->withoutOverlapping();
        }

        if (WatchtowerAgent::feature('health')) {
            $schedule->command(HealthReportCommand::class)->everyMinute()->withoutOverlapping();
        }

        if (WatchtowerAgent::feature('actions')) {
            $schedule->command(ActionsCommand::class)->everyMinute()->withoutOverlapping();
        }

        if (WatchtowerAgent::feature('shield')) {
            $schedule->command(ShieldSyncCommand::class)->everyMinute()->withoutOverlapping();
        }
    }
}
