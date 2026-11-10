<?php

namespace Cyberwizard\WatchtowerAgent\Console;

use Cyberwizard\WatchtowerAgent\Actions\ActionClient;
use Cyberwizard\WatchtowerAgent\Actions\ActionRunner;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Command;
use Throwable;

class ActionsCommand extends Command
{
    protected $signature = 'watchtower:actions';

    protected $description = 'Pull remote actions from Watch Tower, verify and run them';

    public function handle(ActionClient $client, ActionRunner $runner): int
    {
        if (! WatchtowerAgent::feature('actions')) {
            $this->components->warn('Remote actions are disabled (set WATCHTOWER_AGENT_ACTIONS=true and WATCHTOWER_ACTION_SECRET).');

            return self::SUCCESS;
        }

        if (! $client->configured()) {
            $this->components->error('NIGHTWATCH_BASE_URL and NIGHTWATCH_TOKEN are required for remote actions.');

            return self::FAILURE;
        }

        try {
            $actions = $client->pull();
        } catch (Throwable $e) {
            $this->components->error('Could not pull actions: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($actions as $action) {
            $result = $runner->handle($action);

            if ($result !== null) {
                $this->components->twoColumnDetail("{$result['action_id']} {$result['status']}", $result['message']);
            }
        }

        $this->components->info('Processed '.count($actions).' action(s).');

        return self::SUCCESS;
    }
}
