<?php

namespace Cyberwizard\WatchtowerAgent\Queue;

use Cyberwizard\WatchtowerAgent\Broadcasting\BroadcastRecorder;
use Cyberwizard\WatchtowerAgent\Events\EventRecorder;
use Cyberwizard\WatchtowerAgent\Events\ListenerRunRecorder;
use Cyberwizard\WatchtowerAgent\Mail\MailRecorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Tracks the job a worker is processing and, when it finishes (before Nightwatch digests the
 * attempt on the next loop), lets each recorder emit its in-execution records for it.
 */
class JobLifecycle
{
    private ?CurrentJob $current = null;

    public function __construct(private Container $container) {}

    public function current(): ?CurrentJob
    {
        return $this->current;
    }

    public function processing(JobProcessing $event): void
    {
        Guard::safely(function () use ($event): void {
            $payload = $event->job->payload();

            $this->current = new CurrentJob(
                job: $event->job,
                payload: is_array($payload) ? $payload : [],
                startedAt: hrtime(true),
            );

            $event = Context::getHidden(EventRecorder::CONTEXT_EVENT);
            $listener = Context::getHidden(EventRecorder::CONTEXT_LISTENER);
            $this->current->event = is_array($event) ? $event : null;
            $this->current->listener = is_string($listener) ? $listener : null;

            $this->container->make(MailRecorder::class)->jobStarted($this->current);
        });
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        if ($this->current !== null && $this->current->job === $event->job) {
            $this->current->exception = $event->exception;
        }
    }

    public function finished(JobProcessed|JobFailed|JobReleasedAfterException $event): void
    {
        $current = $this->current;

        if ($current === null || $current->job !== $event->job) {
            return;
        }

        $this->current = null;

        Guard::safely(function () use ($current, $event): void {
            if ($event instanceof JobFailed) {
                $current->exception = $event->exception;
            }

            $status = $this->status($event, $current->job);
            $duration = (int) ((hrtime(true) - $current->startedAt) / 1000);

            $this->container->make(ListenerRunRecorder::class)->finished($current, $status, $duration);
            $this->container->make(BroadcastRecorder::class)->jobFinished($current, $status);
            $this->container->make(MailRecorder::class)->jobFinished($current, $status);
        });

        $this->container->make(EventRecorder::class)->flushSummary();
    }

    private function status(object $event, Job $job): string
    {
        try {
            return match (true) {
                $event instanceof JobFailed => 'failed',
                $event instanceof JobReleasedAfterException => 'released',
                $job->hasFailed() => 'failed',
                $job->isReleased() => 'released',
                default => 'ok',
            };
        } catch (Throwable) {
            return 'ok';
        }
    }
}
