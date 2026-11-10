<?php

namespace Cyberwizard\WatchtowerAgent\Webhooks;

use Closure;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Route middleware "watchtower.webhook:{source}": records each incoming webhook as
 * wt-webhook-in with the provider's delivery id, a duplicate flag (delivery id seen before)
 * and the signature result the app reports through WatchtowerAgent::webhookSignature().
 */
class RecordIncomingWebhook
{
    public const ATTRIBUTE = 'watchtower_webhook';

    public const DEFAULT_DELIVERY_HEADERS = [
        'X-GitHub-Delivery',
        'X-Gitlab-Event-UUID',
        'X-Shopify-Webhook-Id',
        'X-Shopify-Event-Id',
        'svix-id',
        'webhook-id',
        'X-Request-UUID',
        'I-Twilio-Idempotency-Token',
        'X-Event-Id',
    ];

    public function __construct(
        private Recorder $recorder,
        private CacheFactory $cache,
    ) {}

    public function handle(Request $request, Closure $next, string $source = 'default'): mixed
    {
        if (! WatchtowerAgent::feature('webhooks')) {
            return $next($request);
        }

        $started = hrtime(true);
        $state = new WebhookState((string) Str::uuid(), $source);
        $request->attributes->set(self::ATTRIBUTE, $state);

        $delivery = Guard::safely(fn (): ?string => $this->deliveryId($request, $source));
        $duplicate = $delivery !== null && Guard::safely(fn (): bool => $this->seenBefore($source, $delivery), false);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->record($request, $state, 500, $started, $delivery, $duplicate, $exception);

            throw $exception;
        }

        $status = $response instanceof Response ? $response->getStatusCode() : 200;
        $exception = $response->exception ?? null;

        $this->record($request, $state, $status, $started, $delivery, $duplicate, $exception instanceof Throwable ? $exception : null);

        return $response;
    }

    private function record(Request $request, WebhookState $state, int $status, int $started, ?string $delivery, bool $duplicate, ?Throwable $exception): void
    {
        Guard::safely(function () use ($request, $state, $status, $started, $delivery, $duplicate, $exception): void {
            $this->recorder->inExecution('wt-webhook-in', [
                'webhook_id' => $state->id,
                'source' => $state->source,
                'endpoint' => '/'.ltrim((string) ($request->route()?->uri() ?? $request->path()), '/'),
                'method' => $request->getMethod(),
                'signature' => $state->signature,
                'status' => match (true) {
                    $exception !== null || $status >= 500 => 'failed',
                    $status >= 400 => 'rejected',
                    default => 'processed',
                },
                'http_status' => $status,
                'duration' => (int) ((hrtime(true) - $started) / 1000),
                'delivery_id' => $delivery,
                'duplicate' => $duplicate,
                'payload' => $this->payload($request),
                'exception' => $exception === null ? null : Redactor::exception($exception),
            ]);
        });
    }

    private function deliveryId(Request $request, string $source): ?string
    {
        foreach ((array) config('watchtower-agent.webhooks.delivery_headers', self::DEFAULT_DELIVERY_HEADERS) as $header) {
            $value = $request->headers->get((string) $header);

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 255);
            }
        }

        $key = ((array) config('watchtower-agent.webhooks.delivery_payload_keys', ['stripe' => 'id']))[$source] ?? null;

        if (is_string($key) && $key !== '' && $request->isJson()) {
            $value = data_get($request->json()->all(), $key);

            if (is_scalar($value) && (string) $value !== '') {
                return mb_substr((string) $value, 0, 255);
            }
        }

        return null;
    }

    /**
     * Remember the delivery id at the cache STORE level (no cache events, so Nightwatch does
     * not see the agent's own bookkeeping). True when it was already there.
     */
    private function seenBefore(string $source, string $delivery): bool
    {
        $store = $this->cache->store(config('watchtower-agent.webhooks.cache_store'))->getStore();
        $key = 'watchtower-agent:webhook:'.hash('xxh128', $source.'|'.$delivery);
        $ttl = max(60, (int) config('watchtower-agent.webhooks.duplicate_ttl', 86400));

        if (method_exists($store, 'add')) {
            return ! $store->add($key, 1, $ttl);
        }

        if ($store->get($key) !== null) {
            return true;
        }

        $store->put($key, 1, $ttl);

        return false;
    }

    private function payload(Request $request): ?string
    {
        if (! config('watchtower-agent.webhooks.capture_payload', false)) {
            return null;
        }

        $max = (int) config('watchtower-agent.webhooks.max_payload_bytes', 8192);
        $content = (string) $request->getContent();
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return Redactor::json(Redactor::redact($decoded), $max)[0];
        }

        if ($request->request->count() > 0) {
            return Redactor::json(Redactor::redact($request->request->all()), $max)[0];
        }

        return $content === '' ? null : Redactor::cap(Redactor::text($content), $max)[0];
    }
}
