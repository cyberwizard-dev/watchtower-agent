<?php

namespace Cyberwizard\WatchtowerAgent\Events;

use Cyberwizard\WatchtowerAgent\Queue\CurrentJob;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Events\CallQueuedListener;

/**
 * wt-listener-run: a queued listener (CallQueuedListener job) executed by a worker.
 */
class ListenerRunRecorder
{
    public function __construct(private Recorder $recorder) {}

    public function finished(CurrentJob $job, string $status, int $duration): void
    {
        if ($job->commandName() !== CallQueuedListener::class || ! WatchtowerAgent::feature('events')) {
            return;
        }

        $class = $job->serializedString('class') ?? (string) ($job->payload['displayName'] ?? '');
        $method = $job->serializedString('method') ?? 'handle';
        $eventName = $job->event['name'] ?? $job->serializedEventClass() ?? '';

        $this->recorder->inExecution('wt-listener-run', [
            'event_id' => isset($job->event['id']) && is_string($job->event['id']) ? $job->event['id'] : null,
            'event_name' => (string) $eventName,
            'listener' => $job->listener ?? ($class !== '' ? $class.'@'.$method : 'unknown'),
            'job_id' => $job->jobId(),
            'attempt' => $job->attempt(),
            'status' => $status,
            'duration' => $duration,
            'exception' => $status === 'ok' || $job->exception === null ? null : Redactor::exception($job->exception),
        ]);
    }
}
