<?php

use Cyberwizard\WatchtowerAgent\Settings\SettingsRepository;
use Cyberwizard\WatchtowerAgent\Transport;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Http;
use Laravel\Nightwatch\Core;

const SETTINGS_URL = 'https://watchtower.test/api/agent/v1/settings';

/**
 * @param  array<string, float|null>  $sampling
 * @return array<string, mixed>
 */
function agentSettings(array $sampling = [], string $version = 'v1'): array
{
    return [
        'version' => $version,
        'sampling' => array_merge(
            ['requests' => null, 'commands' => null, 'exceptions' => null, 'scheduled_tasks' => null, 'browser' => null],
            $sampling,
        ),
    ];
}

beforeEach(function () {
    $this->transport = $this->fakeTransport();
    SettingsRepository::forgetMemo();

    config([
        'watchtower-agent.settings.base_url' => 'https://watchtower.test/',
        'watchtower-agent.settings.token' => 'env-token',
    ]);
});

afterEach(function () {
    SettingsRepository::forgetMemo();
});

it('stores the settings pulled from Watch Tower', function () {
    Http::fake([SETTINGS_URL => Http::response(agentSettings(['requests' => 0.25]), 200, ['ETag' => '"v1"'])]);

    $this->artisan('watchtower:settings-sync')->assertSuccessful();

    $repository = app(SettingsRepository::class);

    expect($repository->current()['version'])->toBe('v1')
        ->and($repository->sampling())->toBe(['requests' => 0.25, 'commands' => null, 'exceptions' => null, 'scheduled_tasks' => null]);

    Http::assertSent(fn (Request $request) => $request->url() === SETTINGS_URL
        && $request->hasHeader('Authorization', 'Bearer env-token'));
});

it('sends the cached version and keeps the copy on 304', function () {
    Http::fake([SETTINGS_URL => Http::sequence()
        ->push(agentSettings(['requests' => 0.5]))
        ->push('', 304)]);

    $this->artisan('watchtower:settings-sync')->assertSuccessful();
    $this->artisan('watchtower:settings-sync')->expectsOutputToContain('unchanged')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->header('If-None-Match') === ['"v1"']);
    expect(app(SettingsRepository::class)->sampling()['requests'])->toBe(0.5);
});

it('keeps the last good settings when Watch Tower is unreachable', function () {
    Http::fake([SETTINGS_URL => Http::sequence()
        ->push(agentSettings(['commands' => 0.2]))
        ->pushFailedConnection('down')
        ->push('Bad gateway', 502)]);

    $this->artisan('watchtower:settings-sync')->assertSuccessful();
    $this->artisan('watchtower:settings-sync')->expectsOutputToContain('Could not reach')->assertSuccessful();
    $this->artisan('watchtower:settings-sync')->assertSuccessful();

    expect(app(SettingsRepository::class)->sampling()['commands'])->toBe(0.2);
});

it('fails when Watch Tower cannot be reached and nothing is cached', function () {
    Http::fake([SETTINGS_URL => Http::failedConnection('down')]);

    $this->artisan('watchtower:settings-sync')->assertFailed();
});

it('fails when the token or base url is missing', function () {
    config(['watchtower-agent.settings.token' => null]);
    Http::fake();

    $this->artisan('watchtower:settings-sync')->assertFailed();
    Http::assertNothingSent();
});

it('applies the synced rates to Nightwatch and restores the app rate when one is cleared', function () {
    $core = app(Core::class);
    $original = $core->config['sampling']['requests'];

    Http::fake([SETTINGS_URL => Http::sequence()
        ->push(agentSettings(['requests' => 0.1, 'scheduled_tasks' => 0.0]))
        ->push(agentSettings([], 'v2'))]);

    $this->artisan('watchtower:settings-sync')->assertSuccessful();

    expect($core->config['sampling']['requests'])->toBe(0.1)
        ->and($core->config['sampling']['scheduled_tasks'])->toBe(0.0)
        ->and($core->config['sampling']['commands'])->toEqual(1.0);

    $this->artisan('watchtower:settings-sync')->assertSuccessful();

    expect($core->config['sampling']['requests'])->toEqual($original)
        ->and($core->config['sampling']['scheduled_tasks'])->toEqual(1.0);
});

it('clamps rates to 0..1', function () {
    $core = app(Core::class);

    app(Transport::class)->applySampling(['requests' => 4.0, 'commands' => -1.0]);

    expect($core->config['sampling']['requests'])->toBe(1.0)
        ->and($core->config['sampling']['commands'])->toBe(0.0);
});

it('reads the cache at most once a minute per process', function () {
    $core = app(Core::class);
    $repository = app(SettingsRepository::class);

    $repository->store()->forever(SettingsRepository::KEY, ['settings' => agentSettings(['requests' => 0.3]), 'fetched_at' => time()]);
    $repository->apply();
    expect($core->config['sampling']['requests'])->toBe(0.3);

    $repository->store()->forever(SettingsRepository::KEY, ['settings' => agentSettings(['requests' => 0.7]), 'fetched_at' => time()]);
    $repository->apply();
    expect($core->config['sampling']['requests'])->toBe(0.3);

    $this->travel(61)->seconds();
    $repository->apply();
    expect($core->config['sampling']['requests'])->toBe(0.7);
});

it('never records its own call to Watch Tower', function () {
    $pausedDuringCall = null;
    Http::fake(function () use (&$pausedDuringCall) {
        $pausedDuringCall = app(Core::class)->paused();

        return Http::response(agentSettings());
    });

    $this->artisan('watchtower:settings-sync')->assertSuccessful();

    expect($pausedDuringCall)->toBeTrue()
        ->and($this->transport->ofType('wt-http'))->toBe([]);
});

it('does nothing when the settings feature is off', function () {
    config(['watchtower-agent.features.settings' => false]);
    Http::fake();

    $this->artisan('watchtower:settings-sync')->assertSuccessful();
    Http::assertNothingSent();
});

it('applies new rates in long-running queue workers on their loop', function () {
    $core = app(Core::class);
    app(SettingsRepository::class)->store()->forever(SettingsRepository::KEY, ['settings' => agentSettings(['requests' => 0.4]), 'fetched_at' => time()]);
    SettingsRepository::forgetMemo();

    event(new Looping('database', 'default'));

    expect($core->config['sampling']['requests'])->toBe(0.4);
});
