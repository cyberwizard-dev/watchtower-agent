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
 *
 * Session replay (turned on per environment in Watch Tower) uses three more:
 * GET {path}/r.js serves the recorder (cached), GET {path}/c whether and how to
 * record (cached for a minute), POST {path}/r forwards a recorded chunk. A 4xx
 * from Watch Tower on a chunk is passed on so the recorder stops.
 */
class BrowserProxyController
{
    public const MAX_BEACON_BYTES = 65536;

    public const MAX_REPLAY_BYTES = 1048576;

    public const SCRIPT_CACHE_KEY = 'watchtower-agent:browser-script';

    public const RECORDER_CACHE_KEY = 'watchtower-agent:browser-replay-script';

    public const REPLAY_CONFIG_CACHE_KEY = 'watchtower-agent:browser-replay-config';

    public function __construct(private Http $http, private Cache $cache) {}

    public function script(): Response
    {
        return $this->serveScript(self::SCRIPT_CACHE_KEY, '/watchtower-browser.js');
    }

    /** The session replay recorder, loaded by the script only for recorded sessions. */
    public function recorder(): Response
    {
        return $this->serveScript(self::RECORDER_CACHE_KEY, '/watchtower-replay.js');
    }

    /** Whether (and how) the script records sessions; `{"replay": null}` when it must not. */
    public function replayConfig(Request $request): Response
    {
        $off = new Response('{"replay":null}', 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);

        if (! self::active()) {
            return new Response('', 404);
        }

        $body = $this->cache->get(self::REPLAY_CONFIG_CACHE_KEY);

        if (! is_string($body)) {
            try {
                $body = Guard::quietly(fn () => $this->forwarding($request)
                    ->get($this->baseUrl().'/api/browser/v1/'.rawurlencode((string) config('watchtower-agent.browser.key')).'/config')
                    ->throw()
                    ->body());
                $this->cache->put(self::REPLAY_CONFIG_CACHE_KEY, $body, now()->addMinute());
            } catch (Throwable) {
                return $off;
            }
        }

        return new Response($body, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, max-age=60']);
    }

    /** Forward one recorded chunk (gzipped JSON, up to 1 MB). */
    public function replay(Request $request): Response
    {
        if (! self::active()) {
            return new Response('', 404);
        }

        $body = (string) $request->getContent();

        if ($body === '' || strlen($body) > self::MAX_REPLAY_BYTES) {
            return new Response('', 413);
        }

        try {
            $status = Guard::quietly(fn () => $this->forwarding($request)
                ->withBody($body, 'text/plain')
                ->post($this->baseUrl().'/api/browser/v1/'.rawurlencode((string) config('watchtower-agent.browser.key')).'/replay')
                ->status());
        } catch (Throwable) {
            $status = 204;
        }

        // 4xx tells the recorder to stop (replay off, limit reached); anything else is a quiet 204.
        return new Response('', $status >= 400 && $status < 500 ? $status : 204);
    }

    private function serveScript(string $cacheKey, string $path): Response
    {
        if (! self::active()) {
            return new Response('', 404);
        }

        $body = $this->cache->get($cacheKey);

        if (! is_string($body)) {
            try {
                $body = Guard::quietly(fn () => $this->request()->get($this->baseUrl().$path)->throw()->body());
                $this->cache->put($cacheKey, $body, now()->addMinutes(max(1, (int) config('watchtower-agent.browser.script_cache_minutes', 60))));
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
                Guard::quietly(fn () => $this->forwarding($request)
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

    /** A call to Watch Tower on the visitor's behalf: the environment token and the visitor's details. */
    private function forwarding(Request $request): PendingRequest
    {
        return $this->request()
            ->withToken((string) config('watchtower-agent.browser.token'))
            ->withHeaders([
                'X-Watchtower-Origin' => $request->getSchemeAndHttpHost(),
                'X-Watchtower-User-Agent' => (string) $request->userAgent(),
                'X-Watchtower-Client-Ip' => (string) $request->ip(),
            ]);
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
