<?php

namespace Cyberwizard\WatchtowerAgent;

use Closure;
use Cyberwizard\WatchtowerAgent\Health\HealthCheckRegistry;
use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Webhooks\RecordIncomingWebhook;
use Cyberwizard\WatchtowerAgent\Webhooks\WebhookState;

class WatchtowerAgent
{
    public const VERSION = '0.2.0';

    /**
     * Every feature the package knows about, in the order the hello record lists them.
     *
     * @var list<string>
     */
    public const FEATURES = [
        'schedule', 'queues', 'workers', 'output', 'catchup', 'health', 'bindings', 'actions',
        'events', 'broadcasts', 'cache_ops', 'mail', 'http', 'webhooks', 'trace_context',
    ];

    /**
     * Register an application health check. The closure returns a CheckResult (a bool is
     * accepted as healthy/unhealthy); a thrown exception reports the check as unhealthy.
     *
     * @param  Closure(): (Health\CheckResult|bool)  $check
     */
    public static function check(string $name, Closure $check): void
    {
        app(HealthCheckRegistry::class)->register($name, $check);
    }

    /**
     * Guzzle middleware for clients built outside Laravel's Http facade: records wt-http for
     * every attempt and adds a W3C traceparent. Push it after Nightwatch's own middleware:
     * $stack->push(Nightwatch::guzzleMiddleware()); $stack->push(WatchtowerAgent::guzzleMiddleware());
     * Laravel's Http client already has it; don't add it there too.
     */
    public static function guzzleMiddleware(): callable
    {
        return app(HttpRecorder::class);
    }

    /**
     * Report the signature check of the incoming webhook being handled by the
     * watchtower.webhook middleware: "valid", "invalid", "missing" or "unchecked" (true and
     * false are accepted for valid and invalid). Does nothing outside such a request.
     */
    public static function webhookSignature(string|bool $result): void
    {
        Guard::safely(function () use ($result): void {
            $result = match ($result) {
                true => 'valid',
                false => 'invalid',
                default => strtolower($result),
            };

            $state = app('request')->attributes->get(RecordIncomingWebhook::ATTRIBUTE);

            if ($state instanceof WebhookState && in_array($result, WebhookState::SIGNATURES, true)) {
                $state->signature = $result;
            }
        });
    }

    public static function enabled(): bool
    {
        return (bool) config('watchtower-agent.enabled', true);
    }

    public static function feature(string $feature): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return match ($feature) {
            // No reliable correlation key exists in Nightwatch 1.x; see the README.
            'bindings' => false,
            'actions' => (bool) config('watchtower-agent.features.actions', false)
                && filled(config('watchtower-agent.actions.secret')),
            default => (bool) config("watchtower-agent.features.{$feature}", false),
        };
    }

    /**
     * @return list<string>
     */
    public static function features(): array
    {
        return array_values(array_filter(self::FEATURES, self::feature(...)));
    }
}
