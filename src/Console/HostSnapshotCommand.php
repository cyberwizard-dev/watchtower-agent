<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Host\HostSnapshotter;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;

class HostSnapshotCommand extends Command
{
    protected $signature = 'watchtower:host-snapshot';

    protected $description = 'Send server CPU, memory, disk and top process metrics to Watch Tower';

    public function handle(Recorder $recorder, HostSnapshotter $snapshotter): int
    {
        if (! WatchtowerAgent::feature('host')) {
            $this->components->warn('The host feature is disabled.');

            return self::SUCCESS;
        }

        $snapshot = $snapshotter->snapshot();

        $recorder->send('wt-host', $snapshot)
            ? $this->components->info('Sent a host snapshot ('.$snapshot['scope'].', '.count($snapshot['processes']['top']).' process(es), '.count($snapshot['disks']).' disk(s)).')
            : $this->components->warn('wt-host was not sent.');

        return self::SUCCESS;
    }
}
