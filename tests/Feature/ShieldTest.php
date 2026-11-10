<?php

use Cyberwizard\WatchtowerAgent\Console\HelloCommand;
use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Records\RecordThrottle;
use Cyberwizard\WatchtowerAgent\Shield\PassCookie;
use Cyberwizard\WatchtowerAgent\Shield\Patterns;
use Cyberwizard\WatchtowerAgent\Shield\RulesRepository;
use Cyberwizard\WatchtowerAgent\Shield\ShieldMiddleware;
use Cyberwizard\WatchtowerAgent\Shield\ShieldRecorder;
use Cyberwizard\WatchtowerAgent\Shield\ShieldRules;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\Transport;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;

const SHIELD_RULES_URL = 'https://watchtower.test/api/shield/v1/rules';
const SHIELD_SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

beforeEach(function () {
    $this->transport = $this->fakeTransport();

    config([
        'watchtower-agent.features.shield' => true,
        'watchtower-agent.shield.base_url' => 'https://watchtower.test',
        'watchtower-agent.shield.token' => 'env-token',
        'watchtower-agent.shield.turnstile_secret' => 'turnstile-secret',
        'watchtower-agent.shield.max_per_execution' => 1000,
        'watchtower-agent.shield.throttle_seconds' => 0,
    ]);

    Route::any('{path?}', function () {
        app()->instance('shield-test.http-calls-during-request', count(Http::recorded()));

        return 'app ok';
    })->where('path', '.*');
});

afterEach(function () {
    Request::setTrustedProxies([], 0);
    TrustProxies::flushState();
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shieldRules(array $overrides = []): array
{
    return array_replace([
        'version' => 'v1',
        'ttl_seconds' => 60,
        'mode' => 'enforce',
        'allow' => ['ips' => []],
        'block' => ['ips' => [], 'user_agents' => [], 'paths' => [], 'countries' => []],
        'rate_limits' => [],
        'honeypot' => null,
        'challenge' => null,
    ], $overrides);
}

/**
 * Put rules in the cache as if watchtower:shield-sync had just run.
 *
 * @param  array<string, mixed>  $overrides
 */
function cacheShieldRules(array $overrides = [], ?int $fetchedAt = null): void
{
    app(RulesRepository::class)->store()->forever(RulesRepository::KEY, [
        'rules' => ShieldRules::fromArray(shieldRules($overrides))->toArray(),
        'fetched_at' => $fetchedAt ?? Carbon::now()->getTimestamp(),
    ]);
}

function shieldRecords(object $test): array
{
    return $test->transport->ofType('wt-shield');
}

function loginLimit(array $overrides = []): array
{
    return array_replace([
        'name' => 'login', 'methods' => ['POST'], 'paths' => ['/login', '/api/login'], 'limit' => 3,
        'window_seconds' => 60, 'by' => 'ip', 'action' => 'block', 'block_seconds' => 0,
    ], $overrides);
}

function fromIp(object $test, string $ip): object
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip]);
}

it('sits in the global stack right after TrustProxies', function () {
    $middleware = app(Kernel::class)->getGlobalMiddleware();
    $shield = array_search(ShieldMiddleware::class, $middleware, true);

    expect($shield)->toBe(array_search(TrustProxies::class, $middleware, true) + 1)
        ->and(array_count_values($middleware)[ShieldMiddleware::class])->toBe(1);
});

it('blocks a listed IPv4 address with a 403 and records it', function () {
    cacheShieldRules(['block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => 'suspicious score 92', 'expires_at' => null]]]]);

    fromIp($this, '198.51.100.7')->withHeaders(['User-Agent' => 'Mozilla/5.0 (Bad)'])->get('/pricing?ref=x')
        ->assertForbidden()
        ->assertSee('Forbidden.')
        ->assertDontSee('suspicious')
        ->assertDontSee('198.51.100.7');

    $records = shieldRecords($this);

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-shield');
    expect($records[0])->toMatchArray([
        'action' => 'blocked',
        'rule' => 'ip:198.51.100.7/32',
        'ip' => '198.51.100.7',
        'method' => 'GET',
        'path' => '/pricing',
        'user_agent' => 'Mozilla/5.0 (Bad)',
        'user_id' => null,
        'rules_version' => 'v1',
    ]);

    fromIp($this, '198.51.100.8')->get('/pricing')->assertOk()->assertSee('app ok');
    expect(shieldRecords($this))->toHaveCount(1);
});

it('matches IPv6 ranges', function () {
    cacheShieldRules(['block' => ['ips' => [['cidr' => '2001:db8::/32', 'reason' => null, 'expires_at' => null]]]]);

    fromIp($this, '2001:db8:85a3::8a2e:370:7334')->get('/')->assertForbidden();
    fromIp($this, '2001:db9::1')->get('/')->assertOk();
    fromIp($this, '203.0.113.9')->get('/')->assertOk();

    expect(shieldRecords($this)[0]['rule'])->toBe('ip:2001:db8::/32');
});

it('lets the allow list win over every block', function () {
    cacheShieldRules([
        'allow' => ['ips' => ['203.0.113.0/24', '2001:db8::/32']],
        'block' => [
            'ips' => [['cidr' => '203.0.113.7/32', 'reason' => null, 'expires_at' => null]],
            'user_agents' => ['(?i)sqlmap'],
            'paths' => ['/.env'],
        ],
        'rate_limits' => [loginLimit(['limit' => 1])],
        'honeypot' => ['field' => 'wt_hp_website', 'action' => 'block'],
    ]);

    fromIp($this, '203.0.113.7')->withHeaders(['User-Agent' => 'sqlmap/1.7'])->get('/.env')->assertOk();
    fromIp($this, '2001:db8::1')->get('/.env')->assertOk();

    foreach (range(1, 3) as $attempt) {
        fromIp($this, '203.0.113.7')->post('/login', ['wt_hp_website' => 'http://spam.example'])->assertOk();
    }

    expect(shieldRecords($this))->toBe([]);
});

it('ignores expired IP blocks', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');

    cacheShieldRules(['block' => ['ips' => [
        ['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => Carbon::now()->getTimestamp() - 1],
        ['cidr' => '198.51.100.8/32', 'reason' => null, 'expires_at' => Carbon::now()->getTimestamp() + 60],
    ]]]);

    fromIp($this, '198.51.100.7')->get('/')->assertOk();
    fromIp($this, '198.51.100.8')->get('/')->assertForbidden();
});

it('blocks user agents by expression and skips invalid expressions', function () {
    cacheShieldRules(['block' => ['user_agents' => ['(unclosed', '[z-a]', '(?i)sqlmap|nikto|masscan|zgrab', 'a~b#c%d!e@f`g;h|i']]]);

    $this->withHeaders(['User-Agent' => 'SQLMap/1.7 (https://sqlmap.org)'])->get('/')->assertForbidden();
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Firefox'])->get('/')->assertOk();
    // Invalid UTF-8 doesn't slip past.
    $this->withHeaders(['User-Agent' => "nikto\xC3\x28"])->get('/')->assertForbidden();

    expect(array_column(shieldRecords($this), 'rule'))->toBe(['ua', 'ua']);
});

it('never lets a pathological expression hang or throw', function () {
    expect(Patterns::regexMatches('(a+)+$', str_repeat('a', 40).'b'))->toBeFalse()
        ->and(Patterns::regexMatches('(?i)SQLMAP', 'sqlmap'))->toBeTrue()
        ->and(Patterns::regexMatches('(', 'anything'))->toBeFalse()
        ->and(Patterns::ipInRange('10.0.0.1', '10.0.0.0/33'))->toBeFalse()
        ->and(Patterns::ipInRange('10.0.0.1', 'not-an-ip/8'))->toBeFalse()
        ->and(Patterns::ipInRange('garbage', '10.0.0.0/8'))->toBeFalse();
});

it('blocks paths by glob', function (string $path, bool $blocked) {
    cacheShieldRules(['block' => ['paths' => ['/.env', '/wp-login.php', '/.git/*', '/vendor/phpunit/*']]]);

    $response = $this->get($path);

    $blocked ? $response->assertForbidden() : $response->assertOk();
})->with([
    ['/.env', true],
    ['//.env', true],
    ['/%2Eenv', true],
    ['/WP-LOGIN.PHP', true],
    ['/.git/config', true],
    ['/.git/objects/ab/cdef', true],
    ['/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', true],
    ['/.envy', false],
    ['/docs/.env.example', false],
    ['/git/config', false],
]);

it('answers JSON clients with a JSON body', function () {
    cacheShieldRules(['block' => ['paths' => ['/api/*']]]);

    $this->getJson('/api/users')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
});

it('rate limits per window and resets when the window ends', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    cacheShieldRules(['rate_limits' => [loginLimit()]]);

    foreach (range(1, 3) as $attempt) {
        fromIp($this, '203.0.113.1')->post('/login')->assertOk();
    }

    // Other methods, paths and clients are not counted.
    fromIp($this, '203.0.113.1')->get('/login')->assertOk();
    fromIp($this, '203.0.113.1')->post('/register')->assertOk();
    fromIp($this, '203.0.113.2')->post('/login')->assertOk();

    $response = fromIp($this, '203.0.113.1')->post('/login')->assertStatus(429)->assertSee('Too Many Requests.');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $records = shieldRecords($this);
    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-shield');
    expect($records[0])->toMatchArray(['action' => 'rate_limited', 'rule' => 'rate:login', 'method' => 'POST', 'path' => '/login']);

    Carbon::setTestNow(Carbon::now()->addSeconds(61));

    fromIp($this, '203.0.113.1')->post('/api/login')->assertOk();
});

it('keeps a client out for block_seconds once it trips the limit', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    cacheShieldRules(['rate_limits' => [loginLimit(['limit' => 2, 'block_seconds' => 600])]]);

    fromIp($this, '203.0.113.1')->post('/login')->assertOk();
    fromIp($this, '203.0.113.1')->post('/login')->assertOk();

    expect(fromIp($this, '203.0.113.1')->post('/login')->assertStatus(429)->headers->get('Retry-After'))->toBe('600');

    // Past the 60 s window, still inside the penalty.
    Carbon::setTestNow(Carbon::now()->addSeconds(120));
    expect(fromIp($this, '203.0.113.1')->post('/login')->assertStatus(429)->headers->get('Retry-After'))->toBe('480');

    Carbon::setTestNow(Carbon::now()->addSeconds(481));
    fromIp($this, '203.0.113.1')->post('/login')->assertOk();
});

it('keys rate limits by ip and path, and by user with an IP fallback', function () {
    cacheShieldRules(['rate_limits' => [
        loginLimit(['name' => 'paths', 'methods' => [], 'paths' => ['/posts/*'], 'limit' => 1, 'by' => 'ip_path']),
        loginLimit(['name' => 'users', 'methods' => ['PUT'], 'paths' => [], 'limit' => 1, 'by' => 'user']),
    ]]);

    $this->get('/posts/1')->assertOk();
    $this->get('/posts/2')->assertOk();
    $this->get('/posts/1')->assertStatus(429);

    // No authenticated user at this point: counted by IP.
    fromIp($this, '203.0.113.5')->put('/anything')->assertOk();
    fromIp($this, '203.0.113.5')->put('/other')->assertStatus(429);
    fromIp($this, '203.0.113.6')->put('/other')->assertOk();

    expect(array_column(shieldRecords($this), 'rule'))->toBe(['rate:paths', 'rate:users']);
});

it('blocks form posts that fill the honeypot', function () {
    cacheShieldRules(['honeypot' => ['field' => 'wt_hp_website', 'action' => 'block']]);

    $this->post('/contact', ['message' => 'hi', 'wt_hp_website' => ''])->assertOk();
    $this->post('/contact', ['message' => 'hi'])->assertOk();
    $this->get('/contact?wt_hp_website=x')->assertOk();
    $this->post('/contact', ['message' => 'buy now', 'wt_hp_website' => 'http://spam.example'])->assertForbidden();
    $this->postJson('/api/contact', ['wt_hp_website' => 'spam'])->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);

    $records = shieldRecords($this);
    expect($records)->toHaveCount(2);
    Contract::assertRecord($records[0], 'wt-shield');
    expect($records[0])->toMatchArray(['action' => 'honeypot', 'rule' => 'honeypot', 'method' => 'POST', 'path' => '/contact']);
});

it('only flags honeypot hits when the action is flag', function () {
    cacheShieldRules(['honeypot' => ['field' => 'wt_hp_website', 'action' => 'flag']]);

    $this->post('/contact', ['wt_hp_website' => 'spam'])->assertOk()->assertSee('app ok');

    expect(shieldRecords($this))->toHaveCount(1)
        ->and(shieldRecords($this)[0]['action'])->toBe('honeypot');
});

it('renders the honeypot field with the Blade directive', function () {
    cacheShieldRules(['honeypot' => ['field' => 'wt_hp_company', 'action' => 'block']]);

    $html = Blade::render('<form>@watchtowerHoneypot</form>');

    expect($html)->toContain('name="wt_hp_company"')
        ->toContain('tabindex="-1"')
        ->toContain('autocomplete="off"')
        ->toContain('aria-hidden="true"')
        ->toContain('left:-10000px')
        ->and(WatchtowerAgent::honeypotField())->toBe(str_replace(['<form>', '</form>'], '', $html));

    config(['watchtower-agent.features.shield' => false]);

    expect(Blade::render('<form>@watchtowerHoneypot</form>'))->toBe('<form></form>');
});

it('falls back to the configured honeypot field without rules', function () {
    expect(WatchtowerAgent::honeypotField())->toContain('name="wt_hp_website"');
});

it('reports instead of blocking in monitor mode', function () {
    cacheShieldRules([
        'mode' => 'monitor',
        'block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => null]], 'paths' => ['/.env']],
        'rate_limits' => [loginLimit(['limit' => 1])],
        'honeypot' => ['field' => 'wt_hp_website', 'action' => 'block'],
    ]);

    fromIp($this, '198.51.100.7')->get('/')->assertOk();
    fromIp($this, '127.0.0.1')->get('/.env')->assertOk();
    $this->post('/login')->assertOk();
    $this->post('/login')->assertOk();
    $this->post('/contact', ['wt_hp_website' => 'spam'])->assertOk();

    $records = shieldRecords($this);
    foreach ($records as $record) {
        Contract::assertRecord($record, 'wt-shield');
    }

    expect(array_column($records, 'action'))->toBe(['would_block', 'would_block', 'would_block', 'would_block'])
        ->and(array_column($records, 'rule'))->toBe(['ip:198.51.100.7/32', 'path:/.env', 'rate:login', 'honeypot']);
});

it('lets mode_override replace the mode Watch Tower sends', function () {
    cacheShieldRules(['mode' => 'monitor', 'block' => ['paths' => ['/.env']]]);

    config(['watchtower-agent.shield.mode_override' => 'enforce']);
    $this->get('/.env')->assertForbidden();

    cacheShieldRules(['mode' => 'enforce', 'block' => ['paths' => ['/.env']]]);
    config(['watchtower-agent.shield.mode_override' => 'monitor']);
    $this->get('/.env')->assertOk();

    expect(array_column(shieldRecords($this), 'action'))->toBe(['blocked', 'would_block']);
});

describe('challenge', function () {
    beforeEach(function () {
        cacheShieldRules([
            'rate_limits' => [loginLimit(['name' => 'catalog-burst', 'methods' => ['GET'], 'paths' => ['/catalog*'], 'limit' => 1, 'action' => 'challenge'])],
            'challenge' => ['provider' => 'turnstile', 'site_key' => '0x4AAAAsitekey'],
        ]);
    });

    function browser(object $test): object
    {
        return $test->withHeaders(['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8', 'User-Agent' => 'Mozilla/5.0']);
    }

    it('shows browsers a Turnstile interstitial', function () {
        browser($this)->get('/catalog')->assertOk();

        $response = browser($this)->get('/catalog?page=2')
            ->assertStatus(429)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('data-sitekey="0x4AAAAsitekey"', false)
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false)
            ->assertSee('action="/_watchtower/shield/verify"', false)
            ->assertSee('value="/catalog?page=2"', false)
            ->assertDontSee('catalog-burst');

        expect($response->headers->get('Content-Type'))->toContain('text/html');

        $records = shieldRecords($this);
        Contract::assertRecord($records[0], 'wt-shield');
        expect($records[0])->toMatchArray(['action' => 'challenged', 'rule' => 'rate:catalog-burst']);
    });

    it('gives non-browser clients a 429 instead', function () {
        $this->getJson('/catalog')->assertOk();
        $this->getJson('/catalog')->assertStatus(429)->assertHeader('Retry-After')->assertExactJson(['message' => 'Too Many Requests.']);
        $this->get('/catalog', ['Accept' => '*/*'])->assertStatus(429);

        config(['watchtower-agent.shield.turnstile_secret' => null]);
        browser($this)->get('/catalog')->assertStatus(429)->assertSee('Too Many Requests.');

        expect(array_column(shieldRecords($this), 'action'))->toBe(['rate_limited', 'rate_limited', 'rate_limited']);
    });

    it('verifies the token, sets the pass cookie and lets the client through', function () {
        Http::fake([SHIELD_SITEVERIFY_URL => Http::response(['success' => true])]);

        browser($this)->get('/catalog');
        browser($this)->get('/catalog')->assertStatus(429);

        $response = fromIp($this, '127.0.0.1')->post('/_watchtower/shield/verify', [
            'cf-turnstile-response' => 'token-123',
            'return' => '/catalog?page=2',
        ])->assertStatus(303)->assertRedirect('/catalog?page=2');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === SHIELD_SITEVERIFY_URL
            && $request['secret'] === 'turnstile-secret'
            && $request['response'] === 'token-123'
            && $request['remoteip'] === '127.0.0.1');

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === PassCookie::NAME);

        expect($cookie)->not->toBeNull()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getExpiresTime() - time())->toBeGreaterThan(29 * 60)->toBeLessThanOrEqual(30 * 60);

        browser($this)->withUnencryptedCookie(PassCookie::NAME, $cookie->getValue())->get('/catalog')->assertOk()->assertSee('app ok');

        $records = shieldRecords($this);
        expect(array_column($records, 'action'))->toBe(['challenged', 'challenge_passed']);
        Contract::assertRecord($records[1], 'wt-shield');
        expect($records[1])->toMatchArray(['rule' => 'challenge', 'method' => 'POST', 'path' => '/_watchtower/shield/verify'])
            // The siteverify call is the agent's own: no wt-http for it.
            ->and($this->transport->bufferedOfType('wt-http'))->toBe([]);
    });

    it('rejects a failed token', function () {
        Http::fake([SHIELD_SITEVERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $response = $this->post('/_watchtower/shield/verify', ['cf-turnstile-response' => 'bad', 'return' => '//evil.example/phish'])
            ->assertForbidden()
            ->assertSee('data-sitekey="0x4AAAAsitekey"', false)
            ->assertSee('value="/"', false);

        expect($response->headers->getCookies())->toBe([])
            ->and(array_column(shieldRecords($this), 'action'))->toBe(['challenge_failed']);

        // No token at all never reaches Cloudflare.
        $this->post('/_watchtower/shield/verify', ['return' => '/'])->assertForbidden();
        Http::assertSentCount(1);
    });

    it('treats an unreachable siteverify as a failed check', function () {
        Http::fake([SHIELD_SITEVERIFY_URL => Http::failedConnection('down')]);

        $this->post('/_watchtower/shield/verify', ['cf-turnstile-response' => 'token'])->assertForbidden();

        expect(array_column(shieldRecords($this), 'action'))->toBe(['challenge_failed']);
    });

    it('does not accept forged, expired or moved pass cookies', function () {
        browser($this)->get('/catalog');

        $valid = app(PassCookie::class)->make(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']))->getValue();
        [$expires, $signature] = explode('.', $valid);

        browser($this)->withUnencryptedCookie(PassCookie::NAME, ($expires + 3600).'.'.$signature)->get('/catalog')->assertStatus(429);
        browser($this)->withUnencryptedCookie(PassCookie::NAME, 'garbage')->get('/catalog')->assertStatus(429);
        fromIp(browser($this), '203.0.113.50')->withUnencryptedCookie(PassCookie::NAME, $valid)->get('/catalog')->assertOk();
        fromIp(browser($this), '203.0.113.50')->withUnencryptedCookie(PassCookie::NAME, $valid)->get('/catalog')->assertStatus(429);
        fromIp(browser($this), '127.0.0.1')->withUnencryptedCookie(PassCookie::NAME, $valid)->get('/catalog')->assertOk();

        Carbon::setTestNow(Carbon::now()->addMinutes(31));
        cacheShieldRules([
            'rate_limits' => [loginLimit(['name' => 'catalog-burst', 'methods' => ['GET'], 'paths' => ['/catalog*'], 'limit' => 1, 'action' => 'challenge'])],
            'challenge' => ['provider' => 'turnstile', 'site_key' => '0x4AAAAsitekey'],
        ]);
        fromIp(browser($this), '127.0.0.1')->withUnencryptedCookie(PassCookie::NAME, $valid)->get('/catalog')->assertOk();
        fromIp(browser($this), '127.0.0.1')->withUnencryptedCookie(PassCookie::NAME, $valid)->get('/catalog')->assertStatus(429);
    });

    it('throttles verification attempts per IP', function () {
        Http::fake([SHIELD_SITEVERIFY_URL => Http::response(['success' => false])]);

        foreach (range(1, 20) as $attempt) {
            $this->post('/_watchtower/shield/verify', ['cf-turnstile-response' => 't'])->assertForbidden();
        }

        $this->post('/_watchtower/shield/verify', ['cf-turnstile-response' => 't'])->assertStatus(429);
        Http::assertSentCount(20);
    });
});

it('respects trusted proxies when reading the client IP', function () {
    cacheShieldRules(['block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => null]]]]);

    fromIp($this, '10.0.0.2')->withHeaders(['X-Forwarded-For' => '198.51.100.7'])->get('/')->assertOk();

    TrustProxies::at('10.0.0.0/8');

    fromIp($this, '10.0.0.2')->withHeaders(['X-Forwarded-For' => '198.51.100.7'])->get('/')->assertForbidden();

    expect(shieldRecords($this)[0]['ip'])->toBe('198.51.100.7');
});

describe('delivery', function () {
    beforeEach(function () {
        cacheShieldRules(['block' => ['ips' => [['cidr' => '198.51.100.0/24', 'reason' => null, 'expires_at' => null]]]]);
    });

    it('sends decisions immediately instead of buffering them', function () {
        fromIp($this, '198.51.100.7')->get('/')->assertForbidden();

        expect($this->transport->ofType('wt-shield'))->toHaveCount(1)
            ->and($this->transport->bufferedOfType('wt-shield'))->toBe([])
            ->and($this->transport->ofType('wt-shield')[0])->toMatchArray($this->transport->execution);
    });

    it('delivers decisions of requests Nightwatch does not sample, and keeps the request', function () {
        $this->app->instance(RecordTransport::class, new Transport($this->app));
        $this->app->forgetInstance(Recorder::class);
        $this->app->forgetInstance(ShieldRecorder::class);
        // Testbench runs Nightwatch in its console state, so drop the sample directly.
        app(Core::class)->dontSample();

        fromIp($this, '198.51.100.7')->get('/')->assertForbidden();

        $sent = array_values(array_filter($this->ingest->sentNow, fn (array $record): bool => $record['t'] === 'wt-shield'));
        $buffered = array_values(array_filter($this->ingest->written, fn (array $record): bool => ($record['t'] ?? null) === 'wt-shield'));

        expect($sent)->toHaveCount(1)
            ->and($buffered)->toBe([])
            ->and($sent[0]['action'])->toBe('blocked')
            ->and($sent[0]['execution_id'])->not->toBe('')
            ->and(app(Core::class)->sampling())->toBeTrue();
        Contract::assertRecord($sent[0], 'wt-shield');
    });

    it('leaves sampling alone when sample_decisions is off', function () {
        config(['watchtower-agent.shield.sample_decisions' => false]);
        $this->app->instance(RecordTransport::class, new Transport($this->app));
        $this->app->forgetInstance(Recorder::class);
        $this->app->forgetInstance(ShieldRecorder::class);
        app(Core::class)->dontSample();

        fromIp($this, '198.51.100.7')->get('/')->assertForbidden();

        expect(array_column($this->ingest->sentNow, 't'))->toContain('wt-shield')
            ->and(app(Core::class)->sampling())->toBeFalse();
    });

    it('throttles repeats of the same action, rule and ip', function () {
        config(['watchtower-agent.shield.throttle_seconds' => 10]);
        Carbon::setTestNow('2026-11-10 12:00:00');

        foreach (range(1, 5) as $attempt) {
            fromIp($this, '198.51.100.7')->get('/')->assertForbidden();
        }

        fromIp($this, '198.51.100.8')->get('/')->assertForbidden();

        expect(array_column(shieldRecords($this), 'ip'))->toBe(['198.51.100.7', '198.51.100.8'])
            ->and($this->transport->sampled)->toBe(2);

        Carbon::setTestNow('2026-11-10 12:00:09');
        fromIp($this, '198.51.100.7')->get('/')->assertForbidden();
        expect(shieldRecords($this))->toHaveCount(2);

        Carbon::setTestNow('2026-11-10 12:00:11');
        fromIp($this, '198.51.100.7')->get('/')->assertForbidden();
        expect(shieldRecords($this))->toHaveCount(3)
            ->and($this->transport->sampled)->toBe(3);
    });

    it('shares the throttle window between processes through the cache', function () {
        config(['watchtower-agent.shield.throttle_seconds' => 10]);

        fromIp($this, '198.51.100.7')->get('/')->assertForbidden();

        // A fresh process (PHP-FPM keeps nothing between requests) still sees the window.
        app(RecordThrottle::class)->reset();
        fromIp($this, '198.51.100.7')->get('/')->assertForbidden();

        expect(shieldRecords($this))->toHaveCount(1);
    });

    it('still caps decisions per execution', function () {
        config(['watchtower-agent.shield.max_per_execution' => 2]);
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.7']);

        foreach (range(1, 5) as $attempt) {
            app(ShieldRecorder::class)->record($request, 'blocked', "ip:rule-{$attempt}", 'v1');
        }

        expect(shieldRecords($this))->toHaveCount(2)
            ->and($this->transport->sampled)->toBe(2);

        $this->transport->newExecution('00000000-0000-4000-8000-000000000002');
        app(ShieldRecorder::class)->record($request, 'blocked', 'ip:rule-6', 'v1');

        expect(shieldRecords($this))->toHaveCount(3);
    });
});

it('does nothing when the feature is off', function () {
    cacheShieldRules(['block' => ['paths' => ['/.env']]]);
    config(['watchtower-agent.features.shield' => false]);
    Http::fake();

    $this->get('/.env')->assertOk();
    $this->post('/_watchtower/shield/verify')->assertNotFound();

    Http::assertNothingSent();
    expect(shieldRecords($this))->toBe([]);
});

describe('rules sync', function () {
    it('fails open on the first request and fetches the rules after the response', function () {
        Http::fake([SHIELD_RULES_URL => Http::response(shieldRules(['version' => 'v2', 'block' => ['paths' => ['/.env']]]))]);

        // No rules yet: allowed, and the fetch only happens once the response is done.
        $this->get('/.env')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === SHIELD_RULES_URL
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer env-token')
            && ! $request->hasHeader('If-None-Match'));

        $this->get('/.env')->assertForbidden();

        expect(shieldRecords($this)[0]['rules_version'])->toBe('v2')
            ->and($this->transport->bufferedOfType('wt-http'))->toBe([])
            // The agent's cache bookkeeping is invisible to Nightwatch's cache sensor.
            ->and(collect($this->ingest->written)->where('t', 'cache-event')->filter(fn ($record) => str_contains((string) ($record['key'] ?? ''), 'watchtower-agent')))->toBeEmpty();
    });

    it('fetches after the response is sent, never during the request', function () {
        Http::fake([SHIELD_RULES_URL => Http::response(shieldRules())]);

        $this->get('/probe')->assertOk();

        expect(app('shield-test.http-calls-during-request'))->toBe(0);
        Http::assertSentCount(1);
    });

    it('allows everything while Watch Tower is down and there is no copy', function () {
        Http::fake([SHIELD_RULES_URL => Http::failedConnection('Connection timed out')]);

        $this->get('/.env')->assertOk();
        $this->get('/.env')->assertOk();

        expect(app(RulesRepository::class)->current())->toBeNull()
            ->and(shieldRecords($this))->toBe([]);

        // Lazy refreshes are throttled across requests.
        Http::assertSentCount(1);

        $this->artisan('watchtower:shield-sync')->assertFailed();
    });

    it('keeps using the last good copy when Watch Tower is unreachable', function () {
        Carbon::setTestNow('2026-11-10 12:00:00');
        cacheShieldRules(['block' => ['paths' => ['/.env']]], Carbon::now()->getTimestamp() - 3600);

        Http::fake([SHIELD_RULES_URL => Http::response('Bad gateway', 502)]);

        $this->artisan('watchtower:shield-sync')->assertFailed();
        $this->get('/.env')->assertForbidden();

        expect(app(RulesRepository::class)->stale())->toBeTrue();
    });

    it('sends If-None-Match and keeps the copy on 304', function () {
        Carbon::setTestNow('2026-11-10 12:00:00');
        cacheShieldRules(['version' => 'abc123', 'block' => ['paths' => ['/.env']]], Carbon::now()->getTimestamp() - 120);

        Http::fake([SHIELD_RULES_URL => Http::response('', 304)]);

        expect(app(RulesRepository::class)->stale())->toBeTrue();

        $this->artisan('watchtower:shield-sync')->expectsOutputToContain('unchanged')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('If-None-Match', '"abc123"'));

        expect(app(RulesRepository::class)->stale())->toBeFalse()
            ->and(app(RulesRepository::class)->current()->version)->toBe('abc123');

        $this->get('/.env')->assertForbidden();
    });

    it('replaces the copy on 200 and honours ttl_seconds', function () {
        Carbon::setTestNow('2026-11-10 12:00:00');
        Http::fake([SHIELD_RULES_URL => Http::response(shieldRules(['version' => 'v9', 'ttl_seconds' => 30]))]);

        $this->artisan('watchtower:shield-sync')->expectsOutputToContain('v9')->assertSuccessful();

        expect(app(RulesRepository::class)->stale())->toBeFalse();

        Carbon::setTestNow(Carbon::now()->addSeconds(31));

        expect(app(RulesRepository::class)->stale())->toBeTrue();
    });

    it('is unconfigured without the base url or token', function () {
        config(['watchtower-agent.shield.token' => null]);
        Http::fake();

        $this->get('/')->assertOk();
        $this->artisan('watchtower:shield-sync')->assertFailed();

        Http::assertNothingSent();
    });
});

it('drops malformed rules one by one', function () {
    $rules = ShieldRules::fromArray([
        'version' => 'v1',
        'mode' => 'something-else',
        'block' => ['ips' => ['198.51.100.1', ['cidr' => ''], 'x' => 5, ['reason' => 'no cidr']], 'user_agents' => [null, '', 'ok'], 'paths' => 'not-a-list'],
        'rate_limits' => [['name' => 'x', 'limit' => 0, 'window_seconds' => 60], ['name' => 'y', 'limit' => 5, 'window_seconds' => 10, 'by' => 'weird']],
        'honeypot' => ['field' => ''],
        'challenge' => ['provider' => 'recaptcha', 'site_key' => 'k'],
    ]);

    expect($rules->mode)->toBe('monitor')
        ->and($rules->ttlSeconds)->toBe(60)
        ->and(array_column($rules->blockIps, 'cidr'))->toBe(['198.51.100.1'])
        ->and($rules->userAgents)->toBe(['ok'])
        ->and($rules->paths)->toBe([])
        ->and(array_column($rules->rateLimits, 'name'))->toBe(['y'])
        ->and($rules->rateLimits[0]['by'])->toBe('ip')
        ->and($rules->honeypot)->toBeNull()
        ->and($rules->challenge)->toBeNull();
});

it('lists shield in wt-hello and schedules the sync when enabled', function () {
    expect(HelloCommand::payload()['features'])->toContain('shield');

    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->filter(fn ($command) => str_contains($command, 'watchtower:shield-sync'));

    expect($commands)->toHaveCount(1)
        ->and(collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'watchtower:shield-sync'))->expression)->toBe('* * * * *');

    config(['watchtower-agent.features.shield' => false]);

    expect(HelloCommand::payload()['features'])->not->toContain('shield');
});
