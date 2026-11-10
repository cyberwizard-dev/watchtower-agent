<?php

namespace Cyberwizard\WatchtowerAgent\Webhooks;

use Cyberwizard\WatchtowerAgent\Http\HttpRecorder;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

/**
 * spatie/laravel-webhook-server sends with its own Guzzle client (outside Laravel's Http
 * client), so each WebhookCallSucceededEvent / WebhookCallFailedEvent becomes one wt-http
 * record tagged as an outgoing webhook. FinalWebhookCallFailedEvent repeats the last failed
 * attempt and is ignored.
 */
class SpatieWebhookRecorder
{
    public const SUCCEEDED = 'Spatie\WebhookServer\Events\WebhookCallSucceededEvent';

    public const FAILED = 'Spatie\WebhookServer\Events\WebhookCallFailedEvent';

    public function __construct(private Recorder $recorder) {}

    public function handle(object $event): void
    {
        if (Guard::active() || ! WatchtowerAgent::feature('webhooks') || ! WatchtowerAgent::feature('http')) {
            return;
        }

        Guard::safely(function () use ($event): void {
            $url = (string) ($event->webhookUrl ?? '');
            $parts = parse_url($url) ?: [];
            $host = (string) ($parts['host'] ?? '');
            $path = HttpRecorder::normalizePath((string) ($parts['path'] ?? '/'));
            $method = strtoupper((string) ($event->httpVerb ?? 'POST'));
            $response = $event->response ?? null;
            $response = $response instanceof ResponseInterface ? $response : null;
            $error = trim(((string) ($event->errorType ?? '')).': '.((string) ($event->errorMessage ?? '')), ': ');
            $stats = $event->transferStats ?? null;
            $seconds = is_object($stats) && method_exists($stats, 'getTransferTime') ? $stats->getTransferTime() : null;

            $this->recorder->inExecution('wt-http', [
                // Spatie's uuid identifies the webhook call across its attempts; each attempt is its own record.
                'http_id' => (string) Str::uuid(),
                'group' => hash('xxh128', $method.$host.$path),
                'method' => $method,
                'url' => $this->safeUrl($url),
                'host' => $host,
                'path' => $path,
                'attempt' => max(1, (int) ($event->attempt ?? 1)),
                'status_code' => $response?->getStatusCode(),
                'outcome' => match (true) {
                    $response !== null => 'response',
                    preg_match('/timed? ?out|cURL error 28/i', $error) === 1 => 'timeout',
                    default => 'connection-failed',
                },
                'duration' => is_numeric($seconds) ? (int) round($seconds * 1_000_000) : null,
                'connect_timeout' => null,
                'timeout' => null,
                'request_headers' => $this->headers((array) ($event->headers ?? [])),
                'response_headers' => null,
                'request_body' => null,
                'response_body' => null,
                'traceparent' => null,
                'webhook' => [
                    'event' => $this->eventName($event),
                    'destination' => $host,
                ],
            ], (int) config('watchtower-agent.http.max_per_execution', 200));
        });
    }

    private function eventName(object $event): string
    {
        $meta = (array) ($event->meta ?? []);
        $payload = (array) ($event->payload ?? []);

        foreach ([$meta['event'] ?? null, $payload['event'] ?? null, $payload['type'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'webhook';
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>|null
     */
    private function headers(array $headers): ?array
    {
        if (! config('watchtower-agent.http.capture_headers', true) || $headers === []) {
            return null;
        }

        $deny = array_map('strtolower', (array) config('watchtower-agent.http.deny_headers', HttpRecorder::DEFAULT_DENY_HEADERS));
        $clean = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            $clean[$lower] = in_array($lower, $deny, true) || Redactor::sensitiveKey($lower) || str_contains($lower, 'signature')
                ? Redactor::MASK
                : mb_substr(is_array($value) ? implode(', ', $value) : (string) $value, 0, 1000);
        }

        return $clean;
    }

    private function safeUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return (string) preg_replace('#//[^/@]+@#', '//', $url);
        }

        parse_str($query, $params);

        return (string) preg_replace('#//[^/@]+@#', '//', str_replace($query, http_build_query(Redactor::redact($params)), $url));
    }
}
