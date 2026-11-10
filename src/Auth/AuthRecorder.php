<?php

namespace Cyberwizard\WatchtowerAgent\Auth;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * wt-auth: authentication events as security signals. Failed and Lockout carry the attempted
 * identifier, read only from the configured identifier keys (never the password or any other
 * credential) and masked like mail recipients by default.
 */
class AuthRecorder
{
    /**
     * Auth event class => wt-auth event name.
     *
     * @var array<class-string, string>
     */
    public const EVENTS = [
        'Illuminate\Auth\Events\Login' => 'login',
        'Illuminate\Auth\Events\Failed' => 'failed',
        'Illuminate\Auth\Events\Lockout' => 'lockout',
        'Illuminate\Auth\Events\Logout' => 'logout',
        'Illuminate\Auth\Events\PasswordReset' => 'password_reset',
        'Illuminate\Auth\Events\Registered' => 'registered',
        'Illuminate\Auth\Events\Verified' => 'verified',
        'Illuminate\Auth\Events\OtherDeviceLogout' => 'other_device_logout',
    ];

    public const DEFAULT_IDENTIFIER_KEYS = ['email', 'username', 'login', 'phone'];

    public function __construct(private Container $container) {}

    public function handle(object $event): void
    {
        $name = self::EVENTS[$event::class] ?? null;

        if ($name === null || Guard::active() || ! WatchtowerAgent::feature('auth')) {
            return;
        }

        Guard::safely(function () use ($event, $name): void {
            $recorder = $this->container->make(Recorder::class);
            $execution = $recorder->execution();

            $recorder->inExecution(
                'wt-auth',
                fn (): array => $this->payload($event, $name, $execution['execution_source'] ?? null),
                (int) config('watchtower-agent.auth.max_per_execution', 100),
            );
        });
    }

    /**
     * @return array{event: string, guard: ?string, user_id: ?string, identifier: ?string, ip: ?string, user_agent: ?string, remember: ?bool}
     */
    private function payload(object $event, string $name, ?string $source): array
    {
        $request = $name === 'lockout' && ($event->request ?? null) instanceof Request
            ? $event->request
            : $this->currentRequest($source);

        $identifier = match ($name) {
            'failed' => $this->identifierFrom(is_array($event->credentials ?? null) ? $event->credentials : []),
            'lockout' => $request === null ? null : $this->identifierFrom($request->only($this->identifierKeys())),
            default => null,
        };

        $userAgent = $request?->userAgent();

        return [
            'event' => $name,
            'guard' => is_string($event->guard ?? null) ? $event->guard : null,
            'user_id' => $this->userId($event->user ?? null),
            'identifier' => $identifier,
            'ip' => $request?->ip(),
            'user_agent' => is_string($userAgent) && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'remember' => $name === 'login' ? (bool) ($event->remember ?? false) : null,
        ];
    }

    /**
     * The request being handled, only when Nightwatch is capturing an HTTP request.
     */
    private function currentRequest(?string $source): ?Request
    {
        if ($source !== 'request' || ! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        return $request instanceof Request ? $request : null;
    }

    /**
     * The first configured identifier key with a non-empty string value, masked per mode.
     * Nothing else in the array is ever read.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function identifierFrom(array $values): ?string
    {
        $mode = $this->identifierMode();

        if ($mode === 'none') {
            return null;
        }

        foreach ($this->identifierKeys() as $key) {
            $value = $values[$key] ?? null;

            if (is_int($value)) {
                $value = (string) $value;
            }

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = mb_substr(trim($value), 0, 255);

            return $mode === 'full' ? $value : Redactor::email($value);
        }

        return null;
    }

    /**
     * Configured identifier keys, minus anything that looks like a secret (so a "password"
     * entry can never be read).
     *
     * @return list<string>
     */
    private function identifierKeys(): array
    {
        $keys = config('watchtower-agent.auth.identifier_keys', self::DEFAULT_IDENTIFIER_KEYS);

        if (! is_array($keys)) {
            $keys = self::DEFAULT_IDENTIFIER_KEYS;
        }

        return array_values(array_filter(
            $keys,
            static fn (mixed $key): bool => is_string($key) && $key !== '' && ! Redactor::sensitiveKey($key),
        ));
    }

    private function identifierMode(): string
    {
        $mode = config('watchtower-agent.auth.identifier_mode', 'masked');

        return in_array($mode, ['none', 'masked', 'full'], true) ? $mode : 'masked';
    }

    private function userId(mixed $user): ?string
    {
        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_scalar($id) || $id instanceof \Stringable ? (string) $id : null;
    }
}
