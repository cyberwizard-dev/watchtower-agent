<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * POST /_watchtower/shield/verify: checks the Turnstile token with Cloudflare using the app's
 * TURNSTILE_SECRET and, on success, sets wt_shield_pass and redirects back.
 */
class VerifyChallengeController
{
    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Verification attempts per IP per minute (each one is a call to Cloudflare).
     */
    public const ATTEMPTS_PER_MINUTE = 20;

    public function __construct(
        private RulesRepository $repository,
        private ShieldRecorder $recorder,
        private ShieldResponses $responses,
        private PassCookie $pass,
        private Http $http,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! WatchtowerAgent::feature('shield')) {
            abort(404);
        }

        $return = self::safeReturn($request->input('return'));
        $rules = Guard::safely(fn () => $this->repository->current());
        $secret = (string) config('watchtower-agent.shield.turnstile_secret', '');

        // Nothing to verify against: fail open.
        if ($rules === null || $rules->challenge === null || $secret === '') {
            return new RedirectResponse($return, 303);
        }

        if (! $this->withinAttempts($request)) {
            return $this->responses->tooManyRequests($request, 60);
        }

        if ($this->verified($request, $secret)) {
            $this->recorder->record($request, 'challenge_passed', 'challenge', $rules->version);

            $response = new RedirectResponse($return, 303);
            $response->headers->setCookie($this->pass->make($request));

            return $response;
        }

        $this->recorder->record($request, 'challenge_failed', 'challenge', $rules->version);

        return $this->responses->challenge($request, $rules, 403, true, $return);
    }

    /**
     * A same-site path to go back to; anything else (absolute or protocol-relative URLs)
     * becomes "/".
     */
    public static function safeReturn(mixed $return): string
    {
        if (! is_string($return) || ! str_starts_with($return, '/') || str_starts_with($return, '//')
            || str_contains($return, '\\') || preg_match('/[\x00-\x1F\x7F]/', $return)) {
            return '/';
        }

        return $return;
    }

    private function verified(Request $request, string $secret): bool
    {
        $token = $request->input('cf-turnstile-response');

        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Guard::quietly(fn () => $this->http
                ->asForm()
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->withOptions([HttpRecorder::OPTION_IGNORE => true])
                ->post(self::SITEVERIFY_URL, [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => (string) $request->ip(),
                ]));
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }

    private function withinAttempts(Request $request): bool
    {
        return Guard::safely(function () use ($request): bool {
            $limiter = new RateLimiter($this->repository->store());
            $key = Evaluator::RATE_PREFIX.'verify:'.sha1((string) $request->ip());

            if ($limiter->tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
                return false;
            }

            $limiter->hit($key, 60);

            return true;
        }, true);
    }
}
