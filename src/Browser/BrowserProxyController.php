<?php

namespace Cyberwizard\WatchtowerAgent\Browser;

use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The first-party proxy for browser monitoring. GET {path}/s.js serves Watch
 * Tower's script (cached); POST {path}/e forwards a beacon to Watch Tower with
 * the environment token and the visitor's origin, user agent and IP, which
 * Watch Tower only trusts together with that token. Visitors never get an
 * error from either route, whatever happens to Watch Tower.
 */
class BrowserProxyController
{
    public const MAX_BEACON_BYTES = 65536;

    public const SCRIPT_CACHE_KEY = 'watchtower-agent:browser-script';

    public function __construct(private Http $http, private Cache $cache) {}

    public function script(): Response
    {
        if (! self::active()) {
            return new Response('', 404);
        }

        $body = $this->cache->get(self::SCRIPT_CACHE_KEY);

        if (! is_string($body)) {
            try {
                $body = Guard::quietly(fn () => $this->request()->get($this->baseUrl().'/watchtower-browser.js')->throw()->body());
                $this->cache->put(self::SCRIPT_CACHE_KEY, $body, now()->addMinutes(max(1, (int) config('watchtower-agent.browser.script_cache_minutes', 60))));
            } catch (Throwable) {
                return new Response('', 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-store']);
            }
        }

        return new Response($body, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function beacon(Request $request): Response
    {
        if (! self::active()) {
            return new Response('', 404);
        }

        $body = (string) $request->getContent();
        $key = (string) config('watchtower-agent.browser.key');

        if ($key !== '' && $body !== '' && strlen($body) <= self::MAX_BEACON_BYTES) {
            try {
                Guard::quietly(fn () => $this->request()
                    ->withToken((string) config('watchtower-agent.browser.token'))
                    ->withHeaders([
                        'X-Watchtower-Origin' => $request->getSchemeAndHttpHost(),
                        'X-Watchtower-User-Agent' => (string) $request->userAgent(),
                        'X-Watchtower-Client-Ip' => (string) $request->ip(),
                    ])
                    ->withBody($body, 'text/plain')
                    ->post($this->baseUrl().'/api/browser/v1/'.rawurlencode($key)));
            } catch (Throwable) {
                // Monitoring never fails the visitor's page.
            }
        }

        return new Response('', 204);
    }

    /** Routes answer only when browser monitoring and its proxy are configured. */
    public static function active(): bool
    {
        return WatchtowerAgent::feature('browser') && BrowserTag::proxied();
    }

    private function request(): PendingRequest
    {
        $timeout = max(1, (int) config('watchtower-agent.browser.timeout', 3));

        return $this->http
            ->connectTimeout($timeout)
            ->timeout($timeout)
            // The agent's own calls to Watch Tower never produce wt-http or a traceparent.
            ->withOptions([HttpRecorder::OPTION_IGNORE => true]);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('watchtower-agent.browser.base_url'), '/');
    }
}
