<?php

use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;

beforeEach(function () {
    $this->transport = $this->fakeTransport();

    Route::post('/webhooks/github', function () {
        WatchtowerAgent::webhookSignature(request()->header('X-Hub-Signature-256') === 'sha256=good' ? 'valid' : 'invalid');

        return request()->header('X-Hub-Signature-256') === 'sha256=good' ? response()->noContent() : response('bad signature', 401);
    })->middleware('watchtower.webhook:github');

    Route::post('/webhooks/stripe', function () {
        WatchtowerAgent::webhookSignature(true);

        if (request('type') === 'explode') {
            throw new RuntimeException('Handler failed for secret=abc');
        }

        return ['received' => true];
    })->middleware('watchtower.webhook:stripe');

    Route::get('/traced', fn () => 'ok');
});

function webhooks(object $test): array
{
    return $test->transport->bufferedOfType('wt-webhook-in');
}

it('records an incoming webhook with its delivery id and signature result', function () {
    $this->postJson('/webhooks/github', ['action' => 'opened'], [
        'X-GitHub-Delivery' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        'X-Hub-Signature-256' => 'sha256=good',
    ])->assertNoContent();

    $records = webhooks($this);

    expect($records)->toHaveCount(1);
    Contract::assertRecord($records[0], 'wt-webhook-in');
    expect($records[0])->toMatchArray([
        'source' => 'github',
        'endpoint' => '/webhooks/github',
        'method' => 'POST',
        'signature' => 'valid',
        'status' => 'processed',
        'http_status' => 204,
        'delivery_id' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        'duplicate' => false,
        'payload' => null,
        'exception' => null,
    ]);
});

it('flags a repeated delivery id as a duplicate', function () {
    $headers = ['X-GitHub-Delivery' => 'delivery-1', 'X-Hub-Signature-256' => 'sha256=good'];

    $this->postJson('/webhooks/github', [], $headers);
    $this->postJson('/webhooks/github', [], $headers);
    $this->postJson('/webhooks/github', [], ['X-GitHub-Delivery' => 'delivery-2', 'X-Hub-Signature-256' => 'sha256=good']);

    expect(array_column(webhooks($this), 'duplicate'))->toBe([false, true, false])
        // The agent's own bookkeeping is invisible to Nightwatch's cache sensor.
        ->and(collect($this->ingest->written)->where('t', 'cache-event')->pluck('key')->filter(fn ($key) => str_contains((string) $key, 'watchtower-agent')))->toBeEmpty();
});

it('records rejected webhooks', function () {
    $this->postJson('/webhooks/github', [], ['X-Hub-Signature-256' => 'sha256=forged'])->assertStatus(401);

    expect(webhooks($this)[0])->toMatchArray([
        'signature' => 'invalid',
        'status' => 'rejected',
        'http_status' => 401,
        'delivery_id' => null,
    ]);
});

it('reads the Stripe event id from the payload and records failures', function () {
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/webhooks/stripe', ['id' => 'evt_123', 'type' => 'explode']))->toThrow(RuntimeException::class);

    expect(webhooks($this)[0])->toMatchArray([
        'source' => 'stripe',
        'delivery_id' => 'evt_123',
        'signature' => 'valid',
        'status' => 'failed',
        'http_status' => 500,
        'exception' => 'RuntimeException: Handler failed for secret=[redacted]',
    ]);
});

it('records failures rendered by the exception handler', function () {
    $this->postJson('/webhooks/stripe', ['id' => 'evt_9', 'type' => 'explode'])->assertStatus(500);

    expect(webhooks($this)[0])->toMatchArray([
        'status' => 'failed',
        'http_status' => 500,
        'exception' => 'RuntimeException: Handler failed for secret=[redacted]',
    ]);
});

it('captures a redacted, capped payload only when enabled', function () {
    config(['watchtower-agent.webhooks.capture_payload' => true, 'watchtower-agent.webhooks.max_payload_bytes' => 60]);

    $this->postJson('/webhooks/stripe', ['id' => 'evt_1', 'type' => 'invoice.paid', 'client_secret' => 'pi_secret', 'pad' => str_repeat('z', 80)]);

    $payload = webhooks($this)[0]['payload'];

    expect(strlen($payload))->toBeLessThanOrEqual(60)
        ->and($payload)->toStartWith('{"id":"evt_1","type":"invoice.paid","client_secret":"[redac');
});

it('ignores webhookSignature() outside a webhook request and unknown values', function () {
    WatchtowerAgent::webhookSignature('valid');

    $this->postJson('/webhooks/github', [], ['X-Hub-Signature-256' => 'sha256=good']);

    expect(webhooks($this)[0]['signature'])->toBe('valid');
});

it('does nothing when the webhooks feature is off', function () {
    config(['watchtower-agent.features.webhooks' => false]);

    $this->postJson('/webhooks/github', [], ['X-Hub-Signature-256' => 'sha256=good'])->assertNoContent();

    expect(webhooks($this))->toBe([]);
});

it('links an incoming traceparent to this execution', function () {
    $this->get('/traced', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->assertOk();
    $this->get('/traced', ['traceparent' => 'not-valid'])->assertOk();
    $this->get('/traced')->assertOk();

    $links = $this->transport->bufferedOfType('wt-trace-link');

    expect($links)->toHaveCount(1);
    Contract::assertRecord($links[0], 'wt-trace-link');
    expect($links[0])->toMatchArray([
        'parent_trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'parent_span_id' => '00f067aa0ba902b7',
    ]);
});

it('records spatie/laravel-webhook-server calls as tagged wt-http', function () {
    $arguments = fn (?PsrResponse $response, ?string $errorType, ?string $errorMessage, int $attempt) => [
        'POST', 'https://user:pw@hooks.partner.test/in/42?token=abc', ['event' => 'order.paid', 'id' => 1],
        ['Signature' => 'abc', 'Content-Type' => 'application/json'], ['event' => 'order.paid'], [], $attempt,
        $response, $errorType, $errorMessage, '9f1c2d3e-1111-4222-8333-123456789abc', null,
    ];

    Event::dispatch(new WebhookCallFailedEvent(...$arguments(new PsrResponse(500), null, null, 1)));
    Event::dispatch(new FinalWebhookCallFailedEvent(...$arguments(null, 'ConnectException', 'cURL error 28: Operation timed out', 2)));
    Event::dispatch(new WebhookCallSucceededEvent(...$arguments(new PsrResponse(200), null, null, 3)));

    $records = $this->transport->bufferedOfType('wt-http');

    expect($records)->toHaveCount(2);

    foreach ($records as $record) {
        Contract::assertRecord($record, 'wt-http');
    }

    expect($records[0])->toMatchArray([
        'method' => 'POST',
        'host' => 'hooks.partner.test',
        'path' => '/in/{id}',
        'url' => 'https://hooks.partner.test/in/42?token=%5Bredacted%5D',
        'attempt' => 1,
        'status_code' => 500,
        'outcome' => 'response',
        'webhook' => ['event' => 'order.paid', 'destination' => 'hooks.partner.test'],
    ])->and($records[0]['request_headers'])->toBe(['signature' => '[redacted]', 'content-type' => 'application/json'])
        ->and($records[1])->toMatchArray(['attempt' => 3, 'status_code' => 200]);
});
