<?php

/*
 * Minimal stand-ins for spatie/laravel-webhook-server 3.x events (not a dev dependency), with
 * the same public properties as Spatie\WebhookServer\Events\WebhookCallEvent.
 */

namespace Spatie\WebhookServer\Events {

    use GuzzleHttp\TransferStats;
    use Psr\Http\Message\ResponseInterface;

    abstract class WebhookCallEvent
    {
        public function __construct(
            public string $httpVerb,
            public string $webhookUrl,
            public array|string $payload,
            public array $headers,
            public array $meta,
            public array $tags,
            public int $attempt,
            public ?ResponseInterface $response,
            public ?string $errorType,
            public ?string $errorMessage,
            public string $uuid,
            public ?TransferStats $transferStats,
        ) {}
    }

    class WebhookCallSucceededEvent extends WebhookCallEvent {}

    class WebhookCallFailedEvent extends WebhookCallEvent {}

    class FinalWebhookCallFailedEvent extends WebhookCallEvent {}
}
