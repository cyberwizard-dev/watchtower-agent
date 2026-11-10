<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Queue\QueueSnapshotter;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;

class QueueSnapshotCommand extends Command
{
    protected $signature = 'watchtower:queue-snapshot';

    protected $description = 'Send queue depth per connection and queue to Watch Tower';

    public function handle(Recorder $recorder, QueueSnapshotter $snapshotter): int
    {
        if (! WatchtowerAgent::feature('queues')) {
            $this->components->warn('The queues feature is disabled.');

            return self::SUCCESS;
        }

        $snapshot = $snapshotter->snapshot();

        $recorder->send('wt-queue-snapshot', $snapshot)
            ? $this->components->info('Sent a snapshot of '.count($snapshot['queues']).' queue(s).')
            : $this->components->warn('wt-queue-snapshot was not sent.');

        return self::SUCCESS;
    }
}
