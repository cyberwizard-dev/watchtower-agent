<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Health\HealthReporter;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;

class HealthReportCommand extends Command
{
    protected $signature = 'watchtower:health-report';

    protected $description = 'Run the in-app health checks and send the results to Watch Tower';

    public function handle(Recorder $recorder, HealthReporter $reporter): int
    {
        if (! WatchtowerAgent::feature('health')) {
            $this->components->warn('The health feature is disabled.');

            return self::SUCCESS;
        }

        $checks = $reporter->run();

        if ($this->output->isVerbose()) {
            $this->table(['Check', 'Status', 'Message', 'ms'], array_map(
                fn (array $check): array => [$check['name'], $check['status'], $check['message'], $check['duration_ms']],
                $checks,
            ));
        }

        $recorder->send('wt-health', ['checks' => $checks])
            ? $this->components->info('Sent '.count($checks).' health check(s).')
            : $this->components->warn('wt-health was not sent.');

        return self::SUCCESS;
    }
}
