<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Closure;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (placed after TrustProxies, so $request->ip() is the client): applies the
 * Shield rules before the app sees the request. Fails open: without cached rules, or on any
 * error inside Shield, the request goes through.
 */
class ShieldMiddleware
{
    public const VERIFY_PATH = '/_watchtower/shield/verify';

    public function __construct(
        private RulesRepository $repository,
        private Evaluator $evaluator,
        private ShieldRecorder $recorder,
        private ShieldResponses $responses,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! WatchtowerAgent::feature('shield') || $request->getPathInfo() === self::VERIFY_PATH) {
            return $next($request);
        }

        $response = Guard::safely(fn (): ?Response => $this->decide($request));

        return $response ?? $next($request);
    }

    /**
     * The response that replaces the app's, or null to let the request through.
     */
    private function decide(Request $request): ?Response
    {
        if ($this->repository->stale()) {
            $this->repository->refreshAfterResponse();
        }

        $rules = $this->repository->current();

        if ($rules === null) {
            return null;
        }

        $verdict = $this->evaluator->evaluate($request, $rules);

        if ($verdict->allowed()) {
            return null;
        }

        if ($verdict->kind === Verdict::FLAG) {
            $this->recorder->record($request, 'honeypot', (string) $verdict->rule, $rules->version);

            return null;
        }

        if (self::mode($rules) === 'monitor') {
            $this->recorder->record($request, 'would_block', (string) $verdict->rule, $rules->version);

            return null;
        }

        if ($verdict->kind === Verdict::CHALLENGE && $this->responses->canChallenge($request, $rules)) {
            $this->recorder->record($request, 'challenged', (string) $verdict->rule, $rules->version);

            return $this->responses->challenge($request, $rules);
        }

        if ($verdict->kind === Verdict::CHALLENGE || $verdict->kind === Verdict::RATE_LIMIT) {
            $this->recorder->record($request, 'rate_limited', (string) $verdict->rule, $rules->version);

            return $this->responses->tooManyRequests($request, $verdict->retryAfter);
        }

        $this->recorder->record($request, $verdict->recordAction, (string) $verdict->rule, $rules->version);

        return $this->responses->forbidden($request);
    }

    /**
     * "monitor" or "enforce": the configured override, else what Watch Tower sent.
     */
    public static function mode(ShieldRules $rules): string
    {
        $override = config('watchtower-agent.shield.mode_override');

        return in_array($override, ['monitor', 'enforce'], true) ? $override : $rules->mode;
    }
}
