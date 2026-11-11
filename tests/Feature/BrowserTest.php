<?php

use Cyberwizard\WatchtowerAgent\Browser\BrowserProxyController;
use Cyberwizard\WatchtowerAgent\Browser\BrowserTag;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'watchtower-agent.browser.key' => 'browser-key-123',
        'watchtower-agent.browser.base_url' => 'https://watchtower.test/',
        'watchtower-agent.browser.token' => 'env-token',
        'watchtower-agent.browser.release' => 'sha-abc',
        'watchtower-agent.browser.sample' => null,
        'watchtower-agent.browser.proxy' => true,
    ]);
    Cache::forget(BrowserProxyController::SCRIPT_CACHE_KEY);
});

it('renders a first-party script tag that never contains the server token', function () {
    $html = Blade::render('@watchtowerBrowser');

    expect($html)->toBe('<script src="'.url('/_t/s.js').'" data-key="browser-key-123" data-endpoint="/_t/e" data-release="sha-abc" defer></script>')
        ->not->toContain('env-token');
});

it('loads the script from Watch Tower when the proxy is off', function () {
    config(['watchtower-agent.browser.proxy' => false, 'watchtower-agent.browser.release' => null]);

    expect(BrowserTag::render())->toBe('<script src="https://watchtower.test/watchtower-browser.js" data-key="browser-key-123" defer></script>');
});

it('renders nothing without a browser key or when switched off', function () {
    config(['watchtower-agent.browser.key' => null]);
    expect(BrowserTag::render())->toBe('');

    config(['watchtower-agent.browser.key' => 'k', 'watchtower-agent.features.browser' => false]);
    expect(BrowserTag::render())->toBe('');
});

it('serves Watch Tower\'s script from the app and caches it', function () {
    Http::fake(['watchtower.test/watchtower-browser.js' => Http::response('/* wt */')]);

    $this->get('/_t/s.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8')->assertSee('/* wt */', false);
    $this->get('/_t/s.js')->assertOk();

    Http::assertSentCount(1);
});

it('forwards beacons with the environment token and the visitor\'s details', function () {
    Http::fake(['watchtower.test/api/browser/v1/*' => Http::response('', 204)]);

    $this->call('POST', '/_t/e', [], [], [], [
        'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/131.0',
        'REMOTE_ADDR' => '198.51.100.23',
    ], '{"events":[{"type":"view"}]}')->assertNoContent();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://watchtower.test/api/browser/v1/browser-key-123'
        && $request->hasHeader('Authorization', 'Bearer env-token')
        && $request->hasHeader('X-Watchtower-User-Agent', 'Mozilla/5.0 Chrome/131.0')
        && $request->hasHeader('X-Watchtower-Client-Ip', '198.51.100.23')
        && $request->hasHeader('X-Watchtower-Origin', 'http://localhost')
        && $request->body() === '{"events":[{"type":"view"}]}');
});

it('never fails the visitor when Watch Tower is down', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    $this->call('POST', '/_t/e', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{"events":[]}')->assertNoContent();
    $this->get('/_t/s.js')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('answers 404 until browser monitoring is configured', function () {
    Http::fake();
    config(['watchtower-agent.browser.key' => null]);

    $this->get('/_t/s.js')->assertNotFound();
    $this->call('POST', '/_t/e', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{"events":[]}')->assertNotFound();

    Http::assertNothingSent();
});
