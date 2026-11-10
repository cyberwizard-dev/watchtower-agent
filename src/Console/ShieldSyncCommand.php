<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Shield\RulesRepository;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;

class ShieldSyncCommand extends Command
{
    protected $signature = 'watchtower:shield-sync';

    protected $description = 'Pull the Shield (bot & spam protection) rules from Watch Tower into the cache';

    public function handle(RulesRepository $repository): int
    {
        if (! WatchtowerAgent::feature('shield')) {
            $this->components->warn('Shield is disabled (set WATCHTOWER_AGENT_SHIELD=true).');

            return self::SUCCESS;
        }

        return match ($repository->refresh()) {
            'updated' => $this->done('Shield rules updated (version '.$repository->current()?->version.').'),
            'not_modified' => $this->done('Shield rules unchanged.'),
            'unconfigured' => $this->failed('NIGHTWATCH_BASE_URL and NIGHTWATCH_TOKEN are required for Shield.'),
            default => $this->failed('Could not reach Watch Tower; the last good rules stay in use.'),
        };
    }

    private function done(string $message): int
    {
        $this->components->info($message);

        return self::SUCCESS;
    }

    private function failed(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
