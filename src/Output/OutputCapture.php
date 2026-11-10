<?php

namespace Cyberwizard\WatchtowerAgent\Output;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\OutputTail;
use Cyberwizard\WatchtowerAgent\Support\TaskGroup;
use Cyberwizard\WatchtowerAgent\Support\Trace;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Sends wt-output for scheduled tasks whose output goes to a file, and for commands run with a
 * BufferedOutput (Artisan::call). Terminal output can't be read back, so it is never captured.
 */
class OutputCapture
{
    public function __construct(private Recorder $recorder) {}

    /**
     * With capture_all_task_output, routes a command task's output to a log file before it runs.
     */
    public function taskStarting(ScheduledTaskStarting $event): void
    {
        $this->guard(fn () => $this->prepare($event->task));
    }

    /**
     * schedule-catchup replays call Event::run() directly, without ScheduledTaskStarting, so
     * every event is prepared when the catch-up command starts.
     */
    public function commandStarting(CommandStarting $event): void
    {
        if ($event->command !== 'schedule-catchup:run' || ! config('watchtower-agent.capture_all_task_output', false)) {
            return;
        }

        $this->guard(function (): void {
            foreach (app(Schedule::class)->events() as $task) {
                $this->prepare($task);
            }
        });
    }

    public function taskFinished(ScheduledTaskFinished|ScheduledTaskFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $task = $event->task;

            // A background task is still running here; ScheduledBackgroundTaskFinished reports it.
            if ($task->runInBackground && $task->command !== null) {
                return;
            }

            // Failed console commands fire Finished and then Failed. Report once, on Failed.
            if ($event instanceof ScheduledTaskFinished
                && $task->command !== null
                && $task->exitCode !== null
                && $task->exitCode !== 0) {
                return;
            }

            $this->sendTask($task, Trace::current());
        });
    }

    public function backgroundTaskFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->guard(fn () => $this->sendTask($event->task, null));
    }

    public function commandFinished(CommandFinished $event): void
    {
        if (! $event->output instanceof BufferedOutput || ! config('watchtower-agent.capture_command_output', true)) {
            return;
        }

        $this->guard(function () use ($event): void {
            $tail = OutputTail::fromBufferedOutput($event->output, $this->maxBytes());

            if ($tail === null || $tail->output === '') {
                return;
            }

            $name = (string) ($event->command ?? '');

            $this->recorder->send('wt-output', [
                'kind' => 'command',
                'group' => TaskGroup::command($name),
                'trace_id' => Trace::current(),
                'name' => $name,
                'exit_code' => $event->exitCode,
                'output' => $tail->output,
                'truncated' => $tail->truncated,
            ]);
        });
    }

    public function prepare(Event $task): void
    {
        if (! config('watchtower-agent.capture_all_task_output', false) || $task->command === null) {
            return;
        }

        if (! OutputTail::isCapturedPath($task->output, $task)) {
            $task->storeOutput();
        }
    }

    private function sendTask(Event $task, ?string $traceId): void
    {
        $tail = OutputTail::fromEvent($task, $this->maxBytes());

        if ($tail === null) {
            return;
        }

        $this->recorder->send('wt-output', [
            'kind' => 'scheduled-task',
            'group' => TaskGroup::for($task),
            'trace_id' => $traceId,
            'name' => TaskGroup::name($task),
            'exit_code' => $task->exitCode,
            'output' => $tail->output,
            'truncated' => $tail->truncated,
        ]);
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('watchtower-agent.max_output_bytes', 65536));
    }

    private function guard(callable $callback): void
    {
        if (! WatchtowerAgent::feature('output')) {
            return;
        }

        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
