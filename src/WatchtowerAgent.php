<?php

namespace Cyberwizard\WatchtowerAgent;

use Closure;
use Cyberwizard\WatchtowerAgent\Health\HealthCheckRegistry;
use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Metrics\MetricRecorder;
use Cyberwizard\WatchtowerAgent\Shield\Honeypot;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Webhooks\RecordIncomingWebhook;
use Cyberwizard\WatchtowerAgent\Webhooks\WebhookState;

class WatchtowerAgent
{
    public const VERSION = '0.11.0';

    /**
     * Every feature the package knows about, in the order the hello record lists them.
     *
     * @var list<string>
     */
    public const FEATURES = [
        'schedule', 'queues', 'workers', 'octane', 'output', 'catchup', 'health', 'bindings', 'actions',
        'events', 'broadcasts', 'cache_ops', 'mail', 'http', 'webhooks', 'trace_context', 'auth',
        'notifications', 'shield', 'host', 'settings', 'models', 'metrics',
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
     * Report a custom gauge, e.g. WatchtowerAgent::metric('orders.processed', 42, ['region' => 'eu']).
     *
     * The name is a dotted identifier ([A-Za-z0-9_.:-], up to 120 characters); the value is a
     * number (or a numeric string); tags are flat scalar pairs. Metrics are sent immediately, so
     * Nightwatch's sample rate never drops them. An invalid name or value is ignored, never
     * thrown: a bad metric call must not break the request that reports it.
     *
     * @param  array<string, string|int|float|bool|null>  $tags
     */
    public static function metric(string $name, int|float|string $value, array $tags = []): void
    {
        // The recorder ignores agent-internal calls and wraps the send itself, so a
        // telemetry failure or a malformed metric never reaches the host app.
        app(MetricRecorder::class)->record($name, $value, $tags);
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

    /**
     * The hidden Shield honeypot input (what @watchtowerHoneypot renders); empty when Shield is
     * off. Put it inside forms you want protected from spam bots.
     */
    public static function honeypotField(): string
    {
        return Honeypot::render();
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
            'browser' => (bool) config('watchtower-agent.features.browser', true)
                && filled(config('watchtower-agent.browser.key'))
                && filled(config('watchtower-agent.browser.base_url')),
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
