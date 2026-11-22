<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Octane\SingletonSafetyScanner;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;

class OctaneScanCommand extends Command
{
    protected $signature = 'watchtower:octane-scan';

    protected $description = 'Scan Laravel singleton bindings for request state that may leak between Octane requests';

    public function handle(Recorder $recorder, SingletonSafetyScanner $scanner): int
    {
        if (! WatchtowerAgent::feature('octane')) {
            $this->components->warn('The Octane feature is disabled.');

            return self::SUCCESS;
        }

        $result = $scanner->scan();
        $sent = $recorder->send('wt-octane-scan', $result);

        $this->components->info(sprintf(
            'Scanned %d application singletons; found %d potential issue(s).',
            $result['classes_scanned'],
            $result['findings_count'],
        ));

        if (! $sent) {
            $this->components->warn('The scan report was not sent to Watch Tower.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
