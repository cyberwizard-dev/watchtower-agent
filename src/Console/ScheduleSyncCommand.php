<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Schedule\ScheduleRegistry;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;

class ScheduleSyncCommand extends Command
{
    protected $signature = 'watchtower:schedule-sync';

    protected $description = 'Send the full schedule to Watch Tower';

    public function handle(Recorder $recorder, ScheduleRegistry $registry): int
    {
        if (! WatchtowerAgent::feature('schedule')) {
            $this->components->warn('The schedule feature is disabled.');

            return self::SUCCESS;
        }

        $tasks = $registry->tasks();

        $recorder->send('wt-schedule', ['tasks' => $tasks])
            ? $this->components->info('Sent '.count($tasks).' scheduled task(s).')
            : $this->components->warn('wt-schedule was not sent.');

        return self::SUCCESS;
    }
}
