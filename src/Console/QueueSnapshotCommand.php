<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Queue\HorizonSnapshotter;
use Cyberwizard\WatchtowerAgent\Queue\QueueSnapshotter;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;

class QueueSnapshotCommand extends Command
{
    protected $signature = 'watchtower:queue-snapshot';

    protected $description = 'Send queue depth per connection and queue to Watch Tower';

    public function handle(Recorder $recorder, QueueSnapshotter $snapshotter, HorizonSnapshotter $horizon): int
    {
        if (! WatchtowerAgent::feature('queues')) {
            $this->components->warn('The queues feature is disabled.');

            return self::SUCCESS;
        }

        $snapshot = $snapshotter->snapshot();

        $sent = $recorder->send('wt-queue-snapshot', $snapshot);

        $horizonSnapshot = $horizon->snapshot();
        if ($horizonSnapshot !== null) {
            $sent = $recorder->send('wt-horizon-snapshot', $horizonSnapshot) && $sent;
        }

        $sent
            ? $this->components->info('Sent queue snapshot'.($horizonSnapshot !== null ? ' and Horizon metrics.' : '.'))
            : $this->components->warn('Queue telemetry was not sent.');

        return self::SUCCESS;
    }
}
