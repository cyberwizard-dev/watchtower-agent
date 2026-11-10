<?php

use Cyberwizard\WatchtowerAgent\Actions\ActionSignature;
use Cyberwizard\WatchtowerAgent\Actions\ActionVerifier;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Tests\Support\QueueTables;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const ACTION_SECRET = 'action-secret';

beforeEach(function () {
    $this->transport = $this->fakeTransport();
    QueueTables::create();

    config([
        'watchtower-agent.features.actions' => true,
        'watchtower-agent.actions.base_url' => 'https://watchtower.test/',
        'watchtower-agent.actions.token' => 'env-token',
        'watchtower-agent.actions.secret' => ACTION_SECRET,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signedAction(array $overrides = [], ?string $secret = ACTION_SECRET): array
{
    $action = array_merge([
        'id' => (string) Str::uuid(),
        'type' => 'retry_failed_job',
        'payload' => ['failed_job_id' => 'missing'],
        'issued_at' => time() - 10,
        'expires_at' => time() + 900,
    ], $overrides);

    $action['signature'] ??= ActionSignature::sign(
        $action['id'], $action['type'], $action['payload'], $action['issued_at'], $action['expires_at'], $secret,
    );

    return $action;
}

function insertFailedJob(): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => 'App\\Jobs\\SendInvoice',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'attempts' => 3,
            'data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => 'O:8:"stdClass":0:{}'],
        ]),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);

    return $uuid;
}

function fakeWatchTower(array $actions): void
{
    Http::fake([
        'watchtower.test/api/agent-actions/pull' => Http::response(['actions' => $actions]),
        'watchtower.test/api/agent-actions/*/result' => Http::response(['ok' => true]),
    ]);
}

it('signs the contract message', function () {
    expect(ActionSignature::message('a1', 'retry_failed_job', ['failed_job_id' => 'x/y'], 10, 20))
        ->toBe('a1|retry_failed_job|{"failed_job_id":"x/y"}|10|20');
});

it('retries a failed job end to end with the database failer', function () {
    $uuid = insertFailedJob();
    $action = signedAction(['payload' => ['failed_job_id' => $uuid]]);
    fakeWatchTower([$action]);

    $this->artisan('watchtower:actions')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(json_decode(DB::table('jobs')->value('payload'), true)['attempts'])->toBe(0);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://watchtower.test/api/agent-actions/pull'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer env-token'));

    Http::assertSent(fn (Request $request) => $request->url() === "https://watchtower.test/api/agent-actions/{$action['id']}/result"
        && $request['status'] === 'done'
        && $request->hasHeader('Authorization', 'Bearer env-token'));

    $record = $this->transport->ofType('wt-action-result')[0];
    Contract::assertRecord($record, 'wt-action-result');
    expect($record)->toMatchArray(['action_id' => $action['id'], 'status' => 'done']);
});

it('fails when the failed job does not exist', function () {
    $action = signedAction(['payload' => ['failed_job_id' => 'nope']]);
    fakeWatchTower([$action]);

    $this->artisan('watchtower:actions')->assertSuccessful();

    expect($this->transport->ofType('wt-action-result')[0])->toMatchArray([
        'status' => 'failed',
        'message' => 'Failed job [nope] was not found',
    ]);
});

it('rejects a signature made with another secret', function () {
    $uuid = insertFailedJob();
    fakeWatchTower([signedAction(['payload' => ['failed_job_id' => $uuid]], secret: 'wrong')]);

    $this->artisan('watchtower:actions')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->transport->ofType('wt-action-result')[0])->toMatchArray(['status' => 'rejected', 'message' => 'Invalid signature']);
});

it('rejects a tampered payload', function () {
    $uuid = insertFailedJob();
    $action = signedAction(['payload' => ['failed_job_id' => 'other']]);
    $action['payload'] = ['failed_job_id' => $uuid];
    fakeWatchTower([$action]);

    $this->artisan('watchtower:actions')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->transport->ofType('wt-action-result')[0]['status'])->toBe('rejected');
});

it('rejects expired actions', function () {
    $uuid = insertFailedJob();
    fakeWatchTower([signedAction(['payload' => ['failed_job_id' => $uuid], 'issued_at' => time() - 1000, 'expires_at' => time() - 100])]);

    $this->artisan('watchtower:actions')->assertSuccessful();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->transport->ofType('wt-action-result')[0])->toMatchArray(['status' => 'rejected', 'message' => 'Action expired']);
});

it('rejects action types outside the allow-list', function () {
    fakeWatchTower([signedAction(['type' => 'run_command', 'payload' => ['command' => 'migrate:fresh']])]);

    $this->artisan('watchtower:actions')->assertSuccessful();

    expect($this->transport->ofType('wt-action-result')[0])->toMatchArray([
        'status' => 'rejected',
        'message' => 'Action type [run_command] is not allowed',
    ]);
});

it('never runs the same action twice', function () {
    $uuid = insertFailedJob();
    $action = signedAction(['payload' => ['failed_job_id' => $uuid]]);

    expect(app(ActionVerifier::class)->verify($action, time())->accepted)->toBeTrue();

    $replay = app(ActionVerifier::class)->verify($action, time());
    expect($replay->accepted)->toBeFalse()
        ->and($replay->reason)->toBe('Action already processed')
        ->and($replay->report)->toBeFalse();

    fakeWatchTower([$action]);
    $this->artisan('watchtower:actions')->assertSuccessful();

    // The replayed id is neither run nor reported, so it can't overwrite the first result.
    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->transport->ofType('wt-action-result'))->toBe([]);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/result'));
});

it('does not pull when actions are disabled or unsigned', function () {
    Http::fake();

    config(['watchtower-agent.features.actions' => false]);
    $this->artisan('watchtower:actions')->assertSuccessful();

    config(['watchtower-agent.features.actions' => true, 'watchtower-agent.actions.secret' => null]);
    $this->artisan('watchtower:actions')->assertSuccessful();

    Http::assertNothingSent();
});

it('fails cleanly when Watch Tower is unreachable', function () {
    Http::fake(['*' => Http::response('nope', 503)]);

    $this->artisan('watchtower:actions')->assertFailed();
});

it('only schedules the actions command when actions are enabled', function () {
    $commands = fn () => collect(app(Schedule::class)->events())
        ->pluck('command')->filter()->map(fn (string $command) => trim(str($command)->after('artisan'), "' "))->values()->all();

    expect($commands())->toContain('watchtower:actions');
});
