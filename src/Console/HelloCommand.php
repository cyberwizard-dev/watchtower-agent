<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Composer\InstalledVersions;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;
use Throwable;

class HelloCommand extends Command
{
    protected $signature = 'watchtower:hello';

    protected $description = 'Tell Watch Tower which agent version and features this app runs';

    public function handle(Recorder $recorder): int
    {
        if (! WatchtowerAgent::enabled()) {
            $this->components->warn('The Watch Tower agent is disabled.');

            return self::SUCCESS;
        }

        $sent = $recorder->send('wt-hello', self::payload());

        $sent
            ? $this->components->info('Sent wt-hello ('.implode(', ', WatchtowerAgent::features()).').')
            : $this->components->warn('wt-hello was not sent: Nightwatch is missing, disabled or paused, or its agent is unreachable.');

        if (WatchtowerAgent::feature('schedule')) {
            $this->call('watchtower:schedule-sync');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{laravel_version: string, php_version: string, features: list<string>, schedule_catchup_version: ?string}
     */
    public static function payload(): array
    {
        return [
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'features' => WatchtowerAgent::features(),
            'schedule_catchup_version' => self::scheduleCatchupVersion(),
        ];
    }

    private static function scheduleCatchupVersion(): ?string
    {
        try {
            return InstalledVersions::isInstalled('cyberwizard/schedule-catchup')
                ? InstalledVersions::getPrettyVersion('cyberwizard/schedule-catchup')
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
