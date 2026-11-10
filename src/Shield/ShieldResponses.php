<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The responses Shield sends instead of the app's. Bodies are generic: they never name the
 * rule that matched.
 */
class ShieldResponses
{
    public function __construct(private Container $container) {}

    public function forbidden(Request $request): SymfonyResponse
    {
        return $this->plain($request, 403, 'Forbidden.');
    }

    public function tooManyRequests(Request $request, int $retryAfter): SymfonyResponse
    {
        $response = $this->plain($request, 429, 'Too Many Requests.');
        $response->headers->set('Retry-After', (string) max(1, $retryAfter));

        return $response;
    }

    /**
     * Only a browser GET with a Turnstile site key and the app's TURNSTILE_SECRET is challenged;
     * everything else gets a 429.
     */
    public function canChallenge(Request $request, ShieldRules $rules): bool
    {
        return $rules->challenge !== null
            && filled(config('watchtower-agent.shield.turnstile_secret'))
            && self::isBrowserGet($request);
    }

    public static function isBrowserGet(Request $request): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/html')
            && ! $request->ajax()
            && ! $request->wantsJson();
    }

    /**
     * The Turnstile interstitial. It posts the token to the verify route, which redirects back.
     */
    public function challenge(Request $request, ShieldRules $rules, int $status = 429, bool $failed = false, ?string $return = null): SymfonyResponse
    {
        $html = $this->container->make('view')->make('watchtower-agent::shield.challenge', [
            'siteKey' => $rules->challenge['site_key'] ?? '',
            'action' => ShieldMiddleware::VERIFY_PATH,
            'returnTo' => $return ?? $request->getRequestUri(),
            'failed' => $failed,
        ])->render();

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function plain(Request $request, int $status, string $message): SymfonyResponse
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return new JsonResponse(['message' => $message], $status, ['Cache-Control' => 'no-store, private']);
        }

        return new Response($message, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
