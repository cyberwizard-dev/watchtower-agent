<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Cyberwizard\WatchtowerAgent\Support\Guard;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Evaluates the rules in the contract's order: allow list (wins over everything), blocked IPs
 * (v4/v6 CIDR), user agent expressions, path globs, rate limits, honeypot, then a pending
 * challenge. The verdict is what enforce mode does; the middleware applies the mode.
 */
class Evaluator
{
    public const RATE_PREFIX = 'watchtower-agent:shield:rate:';

    public function __construct(
        private RulesRepository $repository,
        private PassCookie $pass,
    ) {}

    public function evaluate(Request $request, ShieldRules $rules): Verdict
    {
        $ip = (string) $request->ip();

        foreach ($rules->allowIps as $cidr) {
            if (Patterns::ipInRange($ip, $cidr)) {
                return Verdict::allow();
            }
        }

        $now = Carbon::now()->getTimestamp();

        foreach ($rules->blockIps as $block) {
            if (($block['expires_at'] === null || $block['expires_at'] > $now) && Patterns::ipInRange($ip, $block['cidr'])) {
                return Verdict::block('ip:'.$block['cidr']);
            }
        }

        $userAgent = (string) $request->userAgent();

        if ($userAgent !== '') {
            foreach ($rules->userAgents as $pattern) {
                if (Patterns::regexMatches($pattern, $userAgent)) {
                    return Verdict::block('ua');
                }
            }
        }

        $path = Patterns::normalizePath($request->getPathInfo());

        foreach ($rules->paths as $glob) {
            if (Patterns::globMatches($glob, $path)) {
                return Verdict::block('path:'.$glob);
            }
        }

        $challenge = null;

        foreach ($rules->rateLimits as $limit) {
            if (! $this->appliesTo($limit, $request->getMethod(), $path)) {
                continue;
            }

            $retryAfter = $this->exceeded($limit, $request, $ip, $path);

            if ($retryAfter === null) {
                continue;
            }

            if ($limit['action'] === 'block') {
                return Verdict::rateLimit('rate:'.$limit['name'], $retryAfter);
            }

            // A solved challenge lets the client through challenge limits for its lifetime.
            if ($challenge === null && ! $this->pass->valid($request)) {
                $challenge = Verdict::challenge('rate:'.$limit['name'], $retryAfter);
            }
        }

        if ($rules->honeypot !== null && $request->isMethod('POST') && $this->filled($request->post($rules->honeypot['field']))) {
            return $rules->honeypot['action'] === 'flag'
                ? Verdict::flag('honeypot')
                : Verdict::block('honeypot', 'honeypot');
        }

        return $challenge ?? Verdict::allow();
    }

    /**
     * @param  array{methods: list<string>, paths: list<string>}  $limit
     */
    private function appliesTo(array $limit, string $method, string $path): bool
    {
        if ($limit['methods'] !== [] && ! in_array(strtoupper($method), $limit['methods'], true)) {
            return false;
        }

        if ($limit['paths'] === []) {
            return true;
        }

        foreach ($limit['paths'] as $glob) {
            if (Patterns::globMatches($glob, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count the request. Seconds until the client may retry when the limit is exceeded (or the
     * client is inside a block_seconds penalty), otherwise null.
     *
     * @param  array{name: string, limit: int, window_seconds: int, by: string, block_seconds: int}  $limit
     */
    private function exceeded(array $limit, Request $request, string $ip, string $path): ?int
    {
        $store = $this->repository->store();
        $now = Carbon::now()->getTimestamp();
        $subject = match ($limit['by']) {
            'user' => ($user = $this->userId($request)) !== null ? 'user:'.$user : 'ip:'.$ip,
            'ip_path' => 'ip:'.$ip.'|'.strtolower($path),
            default => 'ip:'.$ip,
        };
        $key = self::RATE_PREFIX.$limit['name'].':'.sha1($subject);

        $blockedUntil = $store->get($key.':blocked');

        if (is_int($blockedUntil) && $blockedUntil > $now) {
            return $blockedUntil - $now;
        }

        $limiter = new RateLimiter($store);
        $limiter->hit($key, $limit['window_seconds']);

        if ($limiter->attempts($key) <= $limit['limit']) {
            return null;
        }

        if ($limit['block_seconds'] > 0) {
            $store->put($key.':blocked', $now + $limit['block_seconds'], $limit['block_seconds']);

            return $limit['block_seconds'];
        }

        return $limiter->availableIn($key);
    }

    /**
     * The authenticated user for "by: user" limits. Global middleware runs before sessions
     * start, so this is only known for guards that don't need one (tokens); otherwise null and
     * the limit falls back to the IP.
     */
    private function userId(Request $request): ?string
    {
        return Guard::safely(function () use ($request): ?string {
            $id = $request->user()?->getAuthIdentifier();

            return is_scalar($id) ? (string) $id : null;
        });
    }

    private function filled(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }
}
