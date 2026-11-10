<?php

namespace Cyberwizard\WatchtowerAgent\Webhooks;

/**
 * The incoming webhook being handled, stored on the request by the watchtower.webhook
 * middleware so WatchtowerAgent::webhookSignature() can mark the signature result.
 */
final class WebhookState
{
    public const SIGNATURES = ['valid', 'invalid', 'missing', 'unchecked'];

    public string $signature = 'unchecked';

    public function __construct(
        public readonly string $id,
        public readonly string $source,
    ) {}
}
