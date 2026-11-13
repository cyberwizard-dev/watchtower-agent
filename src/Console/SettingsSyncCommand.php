<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Settings\SettingsRepository;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;

class SettingsSyncCommand extends Command
{
    protected $signature = 'watchtower:settings-sync';

    protected $description = 'Pull the settings set in Watch Tower (sample rates) into the cache';

    public function handle(SettingsRepository $repository): int
    {
        if (! WatchtowerAgent::feature('settings')) {
            $this->components->warn('Settings sync is disabled (set WATCHTOWER_AGENT_SETTINGS=true).');

            return self::SUCCESS;
        }

        $result = $repository->refresh();

        if ($result === 'updated' || $result === 'not_modified') {
            $repository->apply();
            $this->components->info($result === 'updated'
                ? 'Settings updated (version '.($repository->current()['version'] ?? '?').').'
                : 'Settings unchanged.');

            return self::SUCCESS;
        }

        if ($result === 'unconfigured') {
            $this->components->error('NIGHTWATCH_BASE_URL and NIGHTWATCH_TOKEN are required to sync settings.');

            return self::FAILURE;
        }

        $age = $repository->age();

        // A short outage is not a failed task: the last good settings stay in use.
        if ($age !== null) {
            $this->components->warn("Could not reach Watch Tower; using the settings fetched {$age} s ago.");

            return self::SUCCESS;
        }

        $this->components->error('Could not reach Watch Tower and no settings are cached yet; the app\'s own sample rates are used.');

        return self::FAILURE;
    }
}
