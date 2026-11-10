<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Records\RecordThrottle;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * wt-shield: one record per decision that is not "allow". Decisions are security signals, so
 * they are sent immediately (still carrying the execution header) instead of buffered: a
 * buffered record is dropped with the rest of an execution Nightwatch does not sample. Repeats
 * of the same (action, rule, ip) are throttled to one per shield.throttle_seconds, and a
 * decision that is sent also forces Nightwatch to keep the request it was made for
 * (shield.sample_decisions), so the blocked request itself is visible at most once per window.
 */
class ShieldRecorder
{
    public const ACTIONS = ['blocked', 'rate_limited', 'challenged', 'challenge_passed', 'challenge_failed', 'honeypot', 'would_block'];

    public function __construct(
        private Recorder $recorder,
        private Container $container,
        private RecordThrottle $throttle,
    ) {}

    public function record(Request $request, string $action, string $rule, string $rulesVersion): void
    {
        Guard::safely(function () use ($request, $action, $rule, $rulesVersion): void {
            $throttleKey = "wt-shield|{$action}|{$rule}|".(string) $request->ip();

            if (! $this->throttle->allow($throttleKey, (int) config('watchtower-agent.shield.throttle_seconds', 10), $this->cacheStore())) {
                return;
            }

            $sent = $this->recorder->sendInExecution(
                'wt-shield',
                fn (): array => $this->payload($request, $action, $rule, $rulesVersion),
                (int) config('watchtower-agent.shield.max_per_execution', 10),
            );

            if ($sent && (bool) config('watchtower-agent.shield.sample_decisions', true)) {
                $this->recorder->sampleExecution();
            }
        });
    }

    private function cacheStore(): ?string
    {
        $store = config('watchtower-agent.shield.cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * @return array{action: string, rule: string, ip: string, method: string, path: string, user_agent: ?string, user_id: ?string, rules_version: string}
     */
    private function payload(Request $request, string $action, string $rule, string $rulesVersion): array
    {
        $userAgent = $request->userAgent();

        return [
            'action' => $action,
            'rule' => mb_substr($rule, 0, 255),
            'ip' => (string) $request->ip(),
            'method' => $request->getMethod(),
            'path' => mb_substr(Patterns::normalizePath($request->getPathInfo()), 0, 2048),
            'user_agent' => is_string($userAgent) && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'user_id' => $this->userId(),
            'rules_version' => $rulesVersion,
        ];
    }

    /**
     * The user only when a guard already resolved one: the record never triggers authentication.
     */
    private function userId(): ?string
    {
        if (! $this->container->resolved('auth')) {
            return null;
        }

        $guard = $this->container->make('auth')->guard();

        if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
            return null;
        }

        $id = $guard->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
