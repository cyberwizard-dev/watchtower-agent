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
            default => $this->unreachable($repository->age()),
        };
    }

    /**
     * A Watch Tower restart or a short outage is not a failed task: the last good rules keep
     * working. It fails once the copy is older than the grace period, or when there is none.
     */
    private function unreachable(?int $age): int
    {
        $grace = max(0, (int) config('watchtower-agent.shield.unreachable_grace_seconds', 900));

        if ($age !== null && $age < $grace) {
            $this->components->warn("Could not reach Watch Tower; using the rules fetched {$age} s ago.");

            return self::SUCCESS;
        }

        return $this->failed($age === null
            ? 'Could not reach Watch Tower and no Shield rules are cached yet; every request is allowed.'
            : 'Could not reach Watch Tower for '.intdiv($age, 60).' min; the last good rules stay in use.');
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
