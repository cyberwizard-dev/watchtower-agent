<?php

use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Transport;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Nightwatch\Core;

const AUTH_TEST_PASSWORD = 'hunter2-Sup3r-S3cret!';

beforeEach(function () {
    $this->transport = $this->fakeTransport();

    config(['watchtower-agent.auth.throttle_seconds' => 0]);
});

afterEach(function () {
    Request::setTrustedProxies([], 0);
});

function authRecords(object $test): array
{
    return [...$test->transport->ofType('wt-auth'), ...$test->transport->bufferedOfType('wt-auth')];
}

function authUser(int|string $id = 42): GenericUser
{
    return new GenericUser(['id' => $id, 'email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]);
}

function bindLoginRequest(array $input = [], array $server = []): Request
{
    $request = Request::create('/login', 'POST', $input, [], [], $server + [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Test)',
    ]);

    app()->instance('request', $request);

    return $request;
}

/**
 * The password must not appear in any key or value, at any depth.
 */
function assertNoPassword(mixed $value, string $path = 'record'): void
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            expect(is_string($key) && stripos($key, 'password') !== false)->toBeFalse("{$path}.{$key} is a password key");
            assertNoPassword($item, "{$path}.{$key}");
        }

        return;
    }

    if (is_string($value)) {
        expect($value)->not->toContain(AUTH_TEST_PASSWORD, "{$path} contains the password");
    }
}

it('records every auth event with the contract shape', function (Closure $event, string $name, array $expected) {
    bindLoginRequest();

    event($event());

    $records = authRecords($this);

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-auth');
    assertNoPassword($records[0]);
    expect($records[0])->toMatchArray(['event' => $name, 'ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Test)'] + $expected);
})->with([
    'login' => [fn () => new Login('web', authUser(), true), 'login', ['guard' => 'web', 'user_id' => '42', 'identifier' => null, 'remember' => true]],
    'failed' => [fn () => new Failed('web', authUser(), ['email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]), 'failed', ['guard' => 'web', 'user_id' => '42', 'identifier' => 'j***@example.com', 'remember' => null]],
    'failed unknown user' => [fn () => new Failed('api', null, ['email' => 'nobody@example.org', 'password' => AUTH_TEST_PASSWORD]), 'failed', ['guard' => 'api', 'user_id' => null, 'identifier' => 'n***@example.org']],
    'logout' => [fn () => new Logout('web', authUser(7)), 'logout', ['guard' => 'web', 'user_id' => '7', 'identifier' => null, 'remember' => null]],
    'password reset' => [fn () => new PasswordReset(authUser()), 'password_reset', ['guard' => null, 'user_id' => '42', 'remember' => null]],
    'registered' => [fn () => new Registered(authUser('01J9ZK')), 'registered', ['guard' => null, 'user_id' => '01J9ZK']],
    'verified' => [fn () => new Verified(authUser()), 'verified', ['guard' => null, 'user_id' => '42']],
    'other device logout' => [fn () => new OtherDeviceLogout('web', authUser()), 'other_device_logout', ['guard' => 'web', 'user_id' => '42']],
]);

it('records login without remember as false', function () {
    event(new Login('web', authUser(), false));

    expect(authRecords($this)[0]['remember'])->toBeFalse();
});

it('reads only the configured identifier keys from credentials', function () {
    event(new Failed('web', null, [
        'password' => AUTH_TEST_PASSWORD,
        'tenant' => 'acme-secret-tenant',
        'username' => 'janedoe',
        'email' => 'jane@example.com',
    ]));

    $record = authRecords($this)[0];

    Contract::assertRecord($record, 'wt-auth');
    assertNoPassword($record);
    // "email" comes first in the default key list; "tenant" is never read.
    expect($record['identifier'])->toBe('j***@example.com')
        ->and(json_encode($record))->not->toContain('acme-secret-tenant')->not->toContain('janedoe');
});

it('falls back through the identifier keys and masks non-email identifiers', function () {
    event(new Failed('web', null, ['username' => 'janedoe', 'password' => AUTH_TEST_PASSWORD]));
    event(new Failed('web', null, ['phone' => '+15550100', 'password' => AUTH_TEST_PASSWORD]));
    event(new Failed('web', null, ['password' => AUTH_TEST_PASSWORD]));

    expect(array_column(authRecords($this), 'identifier'))->toBe(['j***', '+***', null]);
});

it('never reads the password even when it is configured as an identifier key', function () {
    config(['watchtower-agent.auth.identifier_keys' => ['password', 'secret_answer', 'email'], 'watchtower-agent.auth.identifier_mode' => 'full']);

    event(new Failed('web', null, ['password' => AUTH_TEST_PASSWORD, 'secret_answer' => 'blue', 'email' => 'jane@example.com']));

    $record = authRecords($this)[0];

    assertNoPassword($record);
    expect($record['identifier'])->toBe('jane@example.com');
});

it('honours custom identifier keys', function () {
    config(['watchtower-agent.auth.identifier_keys' => ['account_number']]);

    event(new Failed('web', null, ['email' => 'jane@example.com', 'account_number' => 'AC-991', 'password' => AUTH_TEST_PASSWORD]));

    expect(authRecords($this)[0]['identifier'])->toBe('A***');
});

it('applies the identifier modes', function (string $mode, ?string $expected) {
    config(['watchtower-agent.auth.identifier_mode' => $mode]);

    event(new Failed('web', null, ['email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]));

    $record = authRecords($this)[0];

    Contract::assertRecord($record, 'wt-auth');
    assertNoPassword($record);
    expect($record['identifier'])->toBe($expected);
})->with([
    'masked' => ['masked', 'j***@example.com'],
    'full' => ['full', 'jane@example.com'],
    'none' => ['none', null],
    'unknown falls back to masked' => ['plain', 'j***@example.com'],
]);

it('records a lockout with the identifier and ip from its request', function () {
    $request = Request::create('/login', 'POST', [
        'email' => 'jane@example.com',
        'password' => AUTH_TEST_PASSWORD,
        'remember' => 'on',
    ], [], [], ['REMOTE_ADDR' => '198.51.100.23', 'HTTP_USER_AGENT' => 'curl/8.0']);

    // Even outside an HTTP execution the lockout carries its own request.
    $this->transport->execution['execution_source'] = 'job';

    event(new Lockout($request));

    $record = authRecords($this)[0];

    Contract::assertRecord($record, 'wt-auth');
    assertNoPassword($record);
    expect($record)->toMatchArray([
        'event' => 'lockout',
        'guard' => null,
        'user_id' => null,
        'identifier' => 'j***@example.com',
        'ip' => '198.51.100.23',
        'user_agent' => 'curl/8.0',
        'remember' => null,
    ]);
});

it('respects trusted proxies for the client ip', function () {
    Request::setTrustedProxies(['203.0.113.7'], Request::HEADER_X_FORWARDED_FOR);
    bindLoginRequest([], ['HTTP_X_FORWARDED_FOR' => '192.0.2.44']);

    event(new Login('web', authUser(), false));

    expect(authRecords($this)[0]['ip'])->toBe('192.0.2.44');
});

it('ignores forwarded headers from untrusted proxies', function () {
    bindLoginRequest([], ['HTTP_X_FORWARDED_FOR' => '192.0.2.44']);

    event(new Login('web', authUser(), false));

    expect(authRecords($this)[0]['ip'])->toBe('203.0.113.7');
});

it('caps the user agent at 255 characters', function () {
    bindLoginRequest([], ['HTTP_USER_AGENT' => str_repeat('A', 400)]);

    event(new Logout('web', authUser()));

    $record = authRecords($this)[0];

    Contract::assertRecord($record, 'wt-auth');
    expect(mb_strlen($record['user_agent']))->toBe(255);
});

it('leaves ip and user agent empty outside an HTTP execution', function () {
    bindLoginRequest();
    $this->transport->execution['execution_source'] = 'command';

    event(new Registered(authUser()));

    $record = authRecords($this)[0];

    Contract::assertRecord($record, 'wt-auth');
    expect($record['ip'])->toBeNull()->and($record['user_agent'])->toBeNull();
});

it('carries the execution header', function () {
    event(new Login('web', authUser(), false));

    expect(authRecords($this)[0])->toMatchArray($this->transport->execution);
});

it('records nothing when the feature is off', function () {
    config(['watchtower-agent.features.auth' => false]);

    event(new Login('web', authUser(), false));
    event(new Failed('web', null, ['email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]));

    expect(authRecords($this))->toBe([]);
});

it('records nothing when Nightwatch is not capturing', function () {
    $this->transport->execution = null;

    event(new Login('web', authUser(), false));

    expect($this->transport->buffered)->toBe([])->and($this->transport->sent)->toBe([]);
});

it('caps auth records per execution', function () {
    config(['watchtower-agent.auth.max_per_execution' => 2]);

    foreach (range(1, 5) as $attempt) {
        event(new Failed('web', null, ['email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]));
    }

    expect(authRecords($this))->toHaveCount(2);

    $this->transport->newExecution('00000000-0000-4000-8000-000000000002');
    event(new Logout('web', authUser()));

    expect(authRecords($this))->toHaveCount(3);
});

it('sends failed logins and lockouts immediately and buffers the rest', function () {
    bindLoginRequest();

    event(new Failed('web', null, ['email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]));
    event(new Lockout(bindLoginRequest(['email' => 'jane@example.com'])));
    event(new Login('web', authUser(), false));

    expect(array_column($this->transport->ofType('wt-auth'), 'event'))->toBe(['failed', 'lockout'])
        ->and(array_column($this->transport->bufferedOfType('wt-auth'), 'event'))->toBe(['login']);

    foreach ($this->transport->ofType('wt-auth') as $record) {
        Contract::assertRecord($record, 'wt-auth');
        expect($record)->toMatchArray($this->transport->execution);
    }
});

it('delivers failed logins and lockouts of executions Nightwatch does not sample', function () {
    $this->app->instance(RecordTransport::class, new Transport($this->app));
    $this->app->forgetInstance(Recorder::class);
    app(Core::class)->dontSample();

    event(new Failed('web', null, ['email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]));
    event(new Lockout(bindLoginRequest(['email' => 'jane@example.com'])));

    $sent = array_values(array_filter($this->ingest->sentNow, fn (array $record): bool => $record['t'] === 'wt-auth'));

    expect(array_column($sent, 'event'))->toBe(['failed', 'lockout'])
        ->and(array_filter($this->ingest->written, fn (array $record): bool => ($record['t'] ?? null) === 'wt-auth'))->toBe([])
        ->and($sent[0]['execution_id'])->not->toBe('');

    foreach ($sent as $record) {
        Contract::assertRecord($record, 'wt-auth');
        assertNoPassword($record);
    }
});

it('throttles repeated failed logins for the same ip and identifier', function () {
    config(['watchtower-agent.auth.throttle_seconds' => 10]);
    Carbon::setTestNow('2026-11-10 12:00:00');
    bindLoginRequest();

    foreach (range(1, 5) as $attempt) {
        event(new Failed('web', null, ['email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]));
    }

    // Same mask (j***@example.com), different identifier: its own window.
    event(new Failed('web', null, ['email' => 'john@example.com', 'password' => AUTH_TEST_PASSWORD]));
    event(new Lockout(bindLoginRequest(['email' => 'jane@example.com'])));

    foreach (range(1, 3) as $attempt) {
        event(new Login('web', authUser(), false));
    }

    expect(array_column($this->transport->ofType('wt-auth'), 'event'))->toBe(['failed', 'failed', 'lockout'])
        ->and($this->transport->bufferedOfType('wt-auth'))->toHaveCount(3);

    Carbon::setTestNow('2026-11-10 12:00:11');
    event(new Failed('web', null, ['email' => 'jane@example.com', 'password' => AUTH_TEST_PASSWORD]));

    expect($this->transport->ofType('wt-auth'))->toHaveCount(4);

    Carbon::setTestNow();
});

it('lists auth in wt-hello only when enabled', function () {
    $this->artisan('watchtower:hello');
    config(['watchtower-agent.features.auth' => false]);
    $this->artisan('watchtower:hello');

    [$on, $off] = $this->transport->ofType('wt-hello');

    expect($on['features'])->toContain('auth')->and($off['features'])->not->toContain('auth');
});
