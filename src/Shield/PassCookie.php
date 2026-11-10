<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * wt_shield_pass: "{expires}.{hmac}" issued after a solved challenge. The HMAC covers the
 * expiry and the client IP and is keyed with the app key (or a hash of the environment token),
 * so the cookie can't be forged, extended or handed to another client address.
 */
class PassCookie
{
    public const NAME = 'wt_shield_pass';

    public function __construct(private Config $config) {}

    public function make(Request $request): Cookie
    {
        $minutes = max(1, (int) $this->config->get('watchtower-agent.shield.pass_minutes', 30));
        $expires = Carbon::now()->getTimestamp() + $minutes * 60;

        return Cookie::create(
            self::NAME,
            $expires.'.'.$this->signature($expires, (string) $request->ip()),
            $expires,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    /**
     * Read straight from the request: the global middleware runs before EncryptCookies.
     */
    public function valid(Request $request): bool
    {
        $value = $request->cookies->get(self::NAME);

        if (! is_string($value) || ! preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $value, $parts)) {
            return false;
        }

        $expires = (int) $parts[1];

        return $expires > Carbon::now()->getTimestamp()
            && hash_equals($this->signature($expires, (string) $request->ip()), $parts[2]);
    }

    private function signature(int $expires, string $ip): string
    {
        return hash_hmac('sha256', "wt-shield-pass|{$expires}|{$ip}", $this->key());
    }

    private function key(): string
    {
        $appKey = (string) $this->config->get('app.key', '');

        if ($appKey !== '') {
            return $appKey;
        }

        return hash('sha256', 'wt-shield|'.(string) $this->config->get('watchtower-agent.shield.token', ''));
    }
}
