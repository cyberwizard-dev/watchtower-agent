<?php

namespace Cyberwizard\WatchtowerAgent\Http;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\Support\Redactor;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Guzzle middleware behind wt-http and outgoing W3C traceparent. It is added once to Laravel's
 * Http client (Factory::globalMiddleware) and is what WatchtowerAgent::guzzleMiddleware()
 * returns for plain Guzzle clients, so every attempt is recorded exactly once.
 *
 * Requests made with the "watchtower_ignore" option (the agent's own calls to Watch Tower)
 * or while the recursion guard is up are passed through untouched.
 */
class HttpRecorder
{
    public const OPTION_IGNORE = 'watchtower_ignore';

    public const OPTION_WEBHOOK = 'watchtower_webhook';

    public const DEFAULT_DENY_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key'];

    /**
     * Last attempt per request signature in the current execution, for retry numbering.
     *
     * @var array{execution: string|null, attempts: array<string, array{attempt: int, failed: bool}>}
     */
    private array $attempts = ['execution' => null, 'attempts' => []];

    public function __construct(private Container $container) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if (! empty($options[self::OPTION_IGNORE]) || Guard::active() || ! WatchtowerAgent::enabled()) {
                return $handler($request, $options);
            }

            $request = Guard::safely(fn (): RequestInterface => $this->withTraceparent($request), $request);

            if (! WatchtowerAgent::feature('http')) {
                return $handler($request, $options);
            }

            $started = microtime(true);
            $startedAt = hrtime(true);

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $exception) {
                // Some handlers (and Http::fake() stubs) throw instead of rejecting.
                $this->record($request, $options, $started, $startedAt, null, $exception);

                throw $exception;
            }

            return $promise->then(
                function ($response) use ($request, $options, $started, $startedAt) {
                    if ($response instanceof ResponseInterface) {
                        $this->record($request, $options, $started, $startedAt, $response, null);
                    }

                    return $response;
                },
                function ($reason) use ($request, $options, $started, $startedAt) {
                    $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                    $response = $response instanceof ResponseInterface ? $response : null;
                    $this->record($request, $options, $started, $startedAt, $response, $reason instanceof Throwable ? $reason : null);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Add a W3C traceparent built from Nightwatch's trace id unless the request has one.
     */
    public function withTraceparent(RequestInterface $request): RequestInterface
    {
        if (! WatchtowerAgent::feature('trace_context')
            || ! config('watchtower-agent.trace_context.outgoing', true)
            || $request->hasHeader('traceparent')) {
            return $request;
        }

        $traceparent = TraceContext::outgoing($this->container->make(Recorder::class)->execution()['trace_id'] ?? null);

        return $traceparent === null ? $request : $request->withHeader('traceparent', $traceparent);
    }

    /**
     * Record one finished attempt, from a response or a transfer failure.
     *
     * @param  array<string, mixed>  $options
     */
    public function record(RequestInterface $request, array $options, float $started, int $startedAt, ?ResponseInterface $response, ?Throwable $failure): void
    {
        Guard::safely(function () use ($request, $options, $startedAt, $response, $failure): void {
            $uri = $request->getUri();

            if ($this->ignoredHost($uri->getHost())) {
                return;
            }

            $recorder = $this->container->make(Recorder::class);
            $execution = $recorder->execution();

            if ($execution === null) {
                return;
            }

            $outcome = match (true) {
                $response !== null => 'response',
                $this->timedOut($failure) => 'timeout',
                default => 'connection-failed',
            };
            $failed = $outcome !== 'response' || $response->getStatusCode() >= 400;
            $attempt = $this->attempt($execution['execution_id'], $request->getMethod().' '.$this->safeUrl($uri), $failed);
            $path = self::normalizePath($uri->getPath());

            $recorder->inExecution('wt-http', fn (): array => [
                'http_id' => (string) Str::uuid(),
                'group' => hash('xxh128', strtoupper($request->getMethod()).$uri->getHost().$path),
                'method' => strtoupper($request->getMethod()),
                'url' => $this->safeUrl($uri),
                'host' => $uri->getHost(),
                'path' => $path,
                'attempt' => $attempt,
                'status_code' => $response?->getStatusCode(),
                'outcome' => $outcome,
                'duration' => (int) ((hrtime(true) - $startedAt) / 1000),
                'connect_timeout' => $this->seconds($options['connect_timeout'] ?? null),
                'timeout' => $this->seconds($options['timeout'] ?? null),
                'request_headers' => $this->headers($request),
                'response_headers' => $response !== null ? $this->headers($response) : null,
                'request_body' => $this->body($request),
                'response_body' => $response !== null ? $this->body($response) : null,
                'traceparent' => $request->hasHeader('traceparent') ? $request->getHeaderLine('traceparent') : null,
                'webhook' => $this->webhook($options),
            ], (int) config('watchtower-agent.http.max_per_execution', 200));
        });
    }

    /**
     * Path with id-like segments (numbers, UUIDs, ULIDs, long hex or tokens) replaced by
     * "{id}", so /v1/charges/ch_3Nx... and /v1/charges/ch_9Ab... group together.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        $segments = array_map(static function (string $segment): string {
            if ($segment === '') {
                return $segment;
            }

            $isId = preg_match('/^\d+$/', $segment) === 1
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1
                || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $segment) === 1
                || preg_match('/^[0-9a-f]{12,}$/i', $segment) === 1
                || (strlen($segment) >= 16 && preg_match('/\d/', $segment) === 1 && preg_match('/^[\w-]+$/', $segment) === 1);

            return $isId ? '{id}' : $segment;
        }, explode('/', $path));

        return implode('/', $segments);
    }

    private function safeUrl(UriInterface $uri): string
    {
        $uri = $uri->withUserInfo('');
        $query = $uri->getQuery();

        if ($query === '') {
            return (string) $uri;
        }

        $pairs = array_map(static function (string $pair): string {
            $key = urldecode(explode('=', $pair, 2)[0]);

            return Redactor::sensitiveKey($key) ? explode('=', $pair, 2)[0].'='.rawurlencode(Redactor::MASK) : $pair;
        }, explode('&', $query));

        return (string) $uri->withQuery(implode('&', $pairs));
    }

    /**
     * @return array<string, string>|null
     */
    private function headers(MessageInterface $message): ?array
    {
        if (! config('watchtower-agent.http.capture_headers', true)) {
            return null;
        }

        $deny = array_map('strtolower', (array) config('watchtower-agent.http.deny_headers', self::DEFAULT_DENY_HEADERS));
        $headers = [];

        foreach ($message->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);

            $headers[$lower] = in_array($lower, $deny, true) || Redactor::sensitiveKey($lower) || str_contains($lower, 'signature')
                ? Redactor::MASK
                : mb_substr(implode(', ', $values), 0, 1000);
        }

        return $headers === [] ? null : $headers;
    }

    private function body(MessageInterface $message): ?string
    {
        if (! config('watchtower-agent.http.capture_bodies', false)) {
            return null;
        }

        $type = strtolower($message->getHeaderLine('Content-Type'));

        if ($type !== '' && preg_match('/json|text|xml|x-www-form-urlencoded|graphql/', $type) !== 1) {
            return null;
        }

        $stream = $message->getBody();

        if (! $stream->isSeekable()) {
            return null;
        }

        $max = max(0, (int) config('watchtower-agent.http.max_body_bytes', 8192));

        try {
            $position = $stream->tell();
            $stream->rewind();
            $raw = $stream->read($max + 1);
            $stream->seek($position);
        } catch (Throwable) {
            return null;
        }

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $raw = (string) json_encode(Redactor::redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } elseif (str_contains($type, 'x-www-form-urlencoded')) {
            parse_str($raw, $form);
            $raw = http_build_query(Redactor::redact($form));
        } else {
            $raw = Redactor::text($raw);
        }

        return Redactor::cap($raw, $max)[0];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{event: string, destination: string}|null
     */
    private function webhook(array $options): ?array
    {
        $webhook = $options[self::OPTION_WEBHOOK] ?? null;

        if (! is_array($webhook) || ! WatchtowerAgent::feature('webhooks')) {
            return null;
        }

        return [
            'event' => (string) ($webhook['event'] ?? ''),
            'destination' => (string) ($webhook['destination'] ?? ''),
        ];
    }

    private function timedOut(?Throwable $failure): bool
    {
        if ($failure === null) {
            return false;
        }

        for ($exception = $failure; $exception !== null; $exception = $exception->getPrevious()) {
            // Guzzle 8 has dedicated timeout exceptions; Guzzle 7 reports cURL errno 28.
            if (str_contains($exception::class, 'Timeout')) {
                return true;
            }

            if (method_exists($exception, 'getHandlerContext') && (int) ($exception->getHandlerContext()['errno'] ?? 0) === 28) {
                return true;
            }

            if (preg_match('/timed? ?out|cURL error 28/i', $exception->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Laravel's retry() and Guzzle retry middleware re-send the same request after a failure;
     * a repeat of the previous attempt's method and URL right after it failed is numbered as
     * the next attempt.
     */
    private function attempt(string $execution, string $signature, bool $failed): int
    {
        if ($this->attempts['execution'] !== $execution) {
            $this->attempts = ['execution' => $execution, 'attempts' => []];
        }

        $previous = $this->attempts['attempts'][$signature] ?? null;
        $attempt = $previous !== null && $previous['failed'] ? $previous['attempt'] + 1 : 1;

        if (count($this->attempts['attempts']) > 200) {
            $this->attempts['attempts'] = [];
        }

        $this->attempts['attempts'][$signature] = ['attempt' => $attempt, 'failed' => $failed];

        return $attempt;
    }

    private function ignoredHost(string $host): bool
    {
        $ignored = (array) config('watchtower-agent.http.ignore_hosts', []);

        return $ignored !== [] && Str::is($ignored, strtolower($host));
    }

    private function seconds(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
