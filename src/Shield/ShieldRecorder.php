<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * wt-shield: one in-execution record per decision that is not "allow".
 */
class ShieldRecorder
{
    public const ACTIONS = ['blocked', 'rate_limited', 'challenged', 'challenge_passed', 'challenge_failed', 'honeypot', 'would_block'];

    public function __construct(
        private Recorder $recorder,
        private Container $container,
    ) {}

    public function record(Request $request, string $action, string $rule, string $rulesVersion): void
    {
        Guard::safely(function () use ($request, $action, $rule, $rulesVersion): void {
            $this->recorder->inExecution(
                'wt-shield',
                fn (): array => $this->payload($request, $action, $rule, $rulesVersion),
                (int) config('watchtower-agent.shield.max_per_execution', 10),
            );
        });
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
