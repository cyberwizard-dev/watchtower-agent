<?php

use Cyberwizard\WatchtowerAgent\Actions\ActionClient;
use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Http\TraceContext;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->transport = $this->fakeTransport();
});

function httpRecords(object $test): array
{
    return $test->transport->bufferedOfType('wt-http');
}

it('records Laravel Http client requests with sanitised headers and a traceparent', function () {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 201, ['Set-Cookie' => 'session=abc', 'X-Request-Id' => 'r-1'])]);

    Http::withToken('secret-token')
        ->withHeaders(['X-Api-Key' => 'k', 'Stripe-Signature' => 't=1,v1=abc', 'Accept-Language' => 'en'])
        ->timeout(12)
        ->connectTimeout(3)
        ->post('https://user:pass@api.example.com/v1/orders/123?api_key=sk_1&page=2', ['password' => 'hunter2']);

    $records = httpRecords($this);

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-http');

    $record = $records[0];
    $traceparent = $record['traceparent'];

    expect($record)->toMatchArray([
        'method' => 'POST',
        'url' => 'https://api.example.com/v1/orders/123?api_key=%5Bredacted%5D&page=2',
        'host' => 'api.example.com',
        'path' => '/v1/orders/{id}',
        'group' => hash('xxh128', 'POST'.'api.example.com'.'/v1/orders/{id}'),
        'attempt' => 1,
        'status_code' => 201,
        'outcome' => 'response',
        'timeout' => 12.0,
        'connect_timeout' => 3.0,
        'request_body' => null,
        'response_body' => null,
        'webhook' => null,
    ])
        ->and($record['request_headers'])->toMatchArray([
            'authorization' => '[redacted]',
            'x-api-key' => '[redacted]',
            'stripe-signature' => '[redacted]',
            'accept-language' => 'en',
            'traceparent' => $traceparent,
        ])
        ->and($record['response_headers'])->toMatchArray(['set-cookie' => '[redacted]', 'x-request-id' => 'r-1'])
        ->and($traceparent)->toMatch('/^00-00000000000040008000000000000001-[0-9a-f]{16}-01$/');

    Http::assertSent(fn (Request $request) => $request->header('traceparent') === [$traceparent]);
});

it('keeps an existing traceparent header', function () {
    Http::fake();

    Http::withHeaders(['traceparent' => '00-11111111111111111111111111111111-2222222222222222-01'])->get('https://api.example.com');

    expect(httpRecords($this)[0]['traceparent'])->toBe('00-11111111111111111111111111111111-2222222222222222-01');
});

it('numbers retry attempts', function () {
    Http::fake(['*' => Http::sequence()->push('', 500)->push('', 503)->push('ok', 200)->push('ok', 200)]);

    Http::retry(3, 0)->get('https://api.example.com/v1/status');

    expect(array_column(httpRecords($this), 'attempt'))->toBe([1, 2, 3])
        ->and(array_column(httpRecords($this), 'status_code'))->toBe([500, 503, 200]);

    Http::get('https://api.example.com/v1/status');

    expect(httpRecords($this)[3]['attempt'])->toBe(1);
});

it('records connection failures', function () {
    Http::fake(['*' => Http::failedConnection('cURL error 6: Could not resolve host: down.example.com')]);

    expect(fn () => Http::get('https://down.example.com/ping'))->toThrow(ConnectionException::class);

    $record = httpRecords($this)[0];

    Contract::assertRecord($record, 'wt-http');
    expect($record)->toMatchArray([
        'outcome' => 'connection-failed',
        'status_code' => null,
        'response_headers' => null,
    ]);
});

it('records timeouts from a plain Guzzle client with the package middleware', function () {
    $request = new PsrRequest('GET', 'https://slow.example.com/report');
    $stack = HandlerStack::create(new MockHandler([
        class_exists(ConnectTimeoutException::class)
            ? new ConnectTimeoutException('Operation timed out after 1001 milliseconds', $request) // Guzzle 8
            : new ConnectException('cURL error 28: Operation timed out after 1001 milliseconds', $request, null, ['errno' => 28]),
        new PsrResponse(200, ['Content-Type' => 'application/json'], '{"token":"abc","rows":3}'),
    ]));
    $stack->push(WatchtowerAgent::guzzleMiddleware());
    $client = new Client(['handler' => $stack]);

    expect(fn () => $client->get('https://slow.example.com/report', ['timeout' => 1]))->toThrow(TransferException::class);

    config(['watchtower-agent.http.capture_bodies' => true]);
    $client->get('https://slow.example.com/report');

    [$timeout, $retry] = httpRecords($this);

    Contract::assertRecord($timeout, 'wt-http');
    expect($timeout)->toMatchArray(['outcome' => 'timeout', 'timeout' => 1.0, 'attempt' => 1])
        ->and($retry)->toMatchArray(['outcome' => 'response', 'attempt' => 2, 'response_body' => '{"token":"[redacted]","rows":3}'])
        ->and($retry['traceparent'])->toStartWith('00-');
});

it('captures capped, redacted bodies only when enabled', function () {
    Http::fake(['*' => Http::response(['password' => 'p', 'items' => str_repeat('x', 100)])]);

    Http::post('https://api.example.com/login', ['email' => 'a@b.c', 'password' => 'hunter2']);

    expect(httpRecords($this)[0]['request_body'])->toBeNull();

    config(['watchtower-agent.http.capture_bodies' => true, 'watchtower-agent.http.max_body_bytes' => 50]);
    Http::post('https://api.example.com/login', ['email' => 'a@b.c', 'password' => 'hunter2']);

    $record = httpRecords($this)[1];

    expect($record['request_body'])->toBe('{"email":"a@b.c","password":"[redacted]"}')
        ->and(strlen($record['response_body']))->toBeLessThanOrEqual(50)
        ->and($record['response_body'])->toStartWith('{"password":"[redacted]"');
});

it('tags outgoing webhooks', function () {
    Http::fake();

    Http::withWatchtowerWebhook('order.paid', 'acme-erp')->post('https://hooks.acme.test/in', ['id' => 1]);

    $record = httpRecords($this)[0];

    Contract::assertRecord($record, 'wt-http');
    expect($record['webhook'])->toBe(['event' => 'order.paid', 'destination' => 'acme-erp']);
});

it('never records or traces the agent\'s own calls to Watch Tower', function () {
    config([
        'watchtower-agent.actions.base_url' => 'https://watchtower.test',
        'watchtower-agent.actions.token' => 'env-token',
    ]);
    Http::fake(['watchtower.test/*' => Http::response(['actions' => []])]);

    app(ActionClient::class)->pull();

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('traceparent'));
    expect(httpRecords($this))->toBe([]);

    Guard::quietly(fn () => Http::get('https://watchtower.test/other'));
    expect(httpRecords($this))->toBe([]);
});

it('does not duplicate Nightwatch\'s outgoing-request record', function () {
    Http::fake();

    Http::get('https://api.example.com/once');

    $types = array_count_values(array_column($this->ingest->written, 't'));

    expect($types['outgoing-request'] ?? 0)->toBe(1)
        ->and(httpRecords($this))->toHaveCount(1);
});

it('skips ignored hosts and respects the feature flags', function () {
    Http::fake();
    config(['watchtower-agent.http.ignore_hosts' => ['*.internal']]);

    Http::get('https://billing.internal/x');
    expect(httpRecords($this))->toBe([]);

    config(['watchtower-agent.features.http' => false]);
    Http::get('https://api.example.com/x');
    expect(httpRecords($this))->toBe([]);

    // Tracing is its own feature: the header is still added.
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/x' && $request->hasHeader('traceparent'));

    config(['watchtower-agent.features.trace_context' => false]);
    Http::get('https://api.example.com/y');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/y' && ! $request->hasHeader('traceparent'));
});

it('builds and parses W3C traceparent values', function () {
    expect(TraceContext::outgoing('9f1c2d3e-1111-4222-8333-123456789abc'))->toMatch('/^00-9f1c2d3e111142228333123456789abc-[0-9a-f]{16}-01$/')
        ->and(TraceContext::outgoing('not-a-trace'))->toBeNull()
        ->and(TraceContext::parse('00-9f1c2d3e111142228333123456789abc-00f067aa0ba902b7-01'))->toBe([
            'parent_trace_id' => '9f1c2d3e111142228333123456789abc',
            'parent_span_id' => '00f067aa0ba902b7',
        ])
        ->and(TraceContext::parse('00-00000000000000000000000000000000-00f067aa0ba902b7-01'))->toBeNull()
        ->and(TraceContext::parse('garbage'))->toBeNull();
});

it('normalises paths for grouping', function () {
    expect(HttpRecorder::normalizePath('/v1/customers/cus_N9kd83HDk2kd93/charges/42'))->toBe('/v1/customers/{id}/charges/{id}')
        ->and(HttpRecorder::normalizePath('repos/laravel/framework'))->toBe('/repos/laravel/framework')
        ->and(HttpRecorder::normalizePath('/files/9f1c2d3e-1111-4222-8333-123456789abc'))->toBe('/files/{id}');
});
