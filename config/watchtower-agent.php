<?php

$csv = static fn (?string $value): ?array => $value === null || trim($value) === ''
    ? null
    : array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turns every feature off at once. Records are also skipped whenever
    | Nightwatch itself is disabled or paused.
    |
    */

    'enabled' => (bool) env('WATCHTOWER_AGENT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | "bindings" is reserved and currently unavailable: Nightwatch gives no
    | reliable key to tie a query record to its bindings (see the README).
    |
    */

    'features' => [
        'schedule' => (bool) env('WATCHTOWER_AGENT_SCHEDULE', true),
        'queues' => (bool) env('WATCHTOWER_AGENT_QUEUES', true),
        'workers' => (bool) env('WATCHTOWER_AGENT_WORKERS', true),
        'output' => (bool) env('WATCHTOWER_AGENT_OUTPUT', true),
        'catchup' => (bool) env('WATCHTOWER_AGENT_CATCHUP', true),
        'health' => (bool) env('WATCHTOWER_AGENT_HEALTH', true),
        'bindings' => (bool) env('WATCHTOWER_AGENT_BINDINGS', false),
        'actions' => (bool) env('WATCHTOWER_AGENT_ACTIONS', false),
        'events' => (bool) env('WATCHTOWER_AGENT_EVENTS', true),
        'broadcasts' => (bool) env('WATCHTOWER_AGENT_BROADCASTS', true),
        'cache_ops' => (bool) env('WATCHTOWER_AGENT_CACHE_OPS', true),
        'mail' => (bool) env('WATCHTOWER_AGENT_MAIL', true),
        'http' => (bool) env('WATCHTOWER_AGENT_HTTP', true),
        'webhooks' => (bool) env('WATCHTOWER_AGENT_WEBHOOKS', true),
        'trace_context' => (bool) env('WATCHTOWER_AGENT_TRACE_CONTEXT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Values whose key matches this pattern are replaced by "[redacted]" in
    | event, broadcast, HTTP and webhook payloads, headers and query strings.
    | The default is the same pattern Watch Tower applies on its side.
    |
    */

    'redaction' => [
        'key_pattern' => env('WATCHTOWER_AGENT_REDACT_KEY_PATTERN', '/pass(word|wd|phrase)?|secret|token|api[_-]?key|private[_-]?key|credential|authorization|cookie|session|credit[_-]?card|card[_-]?number|cvv|cvc|ssn|iban/i'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application events (wt-event, wt-event-summary, wt-listener-run)
    |--------------------------------------------------------------------------
    |
    | Framework and package events (Illuminate\*, Laravel\*, eloquent.*, ...)
    | are skipped by default. "allow" patterns win over every deny pattern;
    | when "allow" is set only matching events are captured. Past
    | max_per_execution events in one request/job/command/task, one
    | wt-event-summary counts the rest. listener_timing replaces the event
    | dispatcher with an instrumented subclass that shares all its state; off
    | means dispatches are captured without listener details.
    |
    */

    'events' => [
        'listener_timing' => (bool) env('WATCHTOWER_AGENT_EVENTS_LISTENER_TIMING', true),
        'allow' => $csv(env('WATCHTOWER_AGENT_EVENTS_ALLOW')) ?? [],
        'deny' => $csv(env('WATCHTOWER_AGENT_EVENTS_DENY')) ?? [],
        'default_deny' => true,
        'sample_rate' => (float) env('WATCHTOWER_AGENT_EVENTS_SAMPLE_RATE', 1.0),
        'max_per_execution' => (int) env('WATCHTOWER_AGENT_EVENTS_MAX_PER_EXECUTION', 50),
        'capture_payload' => (bool) env('WATCHTOWER_AGENT_EVENTS_PAYLOAD', true),
        'max_payload_bytes' => (int) env('WATCHTOWER_AGENT_EVENTS_MAX_PAYLOAD_BYTES', 8192),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting (wt-broadcast)
    |--------------------------------------------------------------------------
    |
    | "sent" only means the driver accepted the publish call. The null driver
    | is ignored by default.
    |
    */

    'broadcasts' => [
        'capture_payload' => (bool) env('WATCHTOWER_AGENT_BROADCASTS_PAYLOAD', true),
        'max_payload_bytes' => (int) env('WATCHTOWER_AGENT_BROADCASTS_MAX_PAYLOAD_BYTES', 8192),
        'ignore_drivers' => $csv(env('WATCHTOWER_AGENT_BROADCASTS_IGNORE_DRIVERS')) ?? ['null'],
        'max_per_execution' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache operations (wt-cache-op)
    |--------------------------------------------------------------------------
    |
    | keys: "masked" (id-like parts become *), "full" or "hidden". locks wraps
    | the lock returned by Cache::lock() to report acquire/block/release; it
    | is off by default because the returned object is then a decorator.
    |
    */

    'cache_ops' => [
        'keys' => env('WATCHTOWER_AGENT_CACHE_KEYS', 'masked'),
        'locks' => (bool) env('WATCHTOWER_AGENT_CACHE_LOCKS', false),
        'max_per_execution' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail (wt-mail)
    |--------------------------------------------------------------------------
    |
    | recipients: "masked" (j***@example.com), "full" or "none". Bcc is only
    | sent when include_bcc is true; the text preview only when preview is.
    | capture_failures wraps each mailer's Symfony transport to see exceptions.
    |
    */

    'mail' => [
        'recipients' => env('WATCHTOWER_AGENT_MAIL_RECIPIENTS', 'masked'),
        'include_bcc' => (bool) env('WATCHTOWER_AGENT_MAIL_BCC', false),
        'preview' => (bool) env('WATCHTOWER_AGENT_MAIL_PREVIEW', false),
        'capture_failures' => (bool) env('WATCHTOWER_AGENT_MAIL_CAPTURE_FAILURES', true),
        'max_per_execution' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing HTTP (wt-http)
    |--------------------------------------------------------------------------
    |
    | Headers are sanitised (deny_headers, anything matching the redaction key
    | pattern and *signature* headers are masked). Bodies are off by default.
    |
    */

    'http' => [
        'capture_headers' => (bool) env('WATCHTOWER_AGENT_HTTP_HEADERS', true),
        'capture_bodies' => (bool) env('WATCHTOWER_AGENT_HTTP_BODIES', false),
        'max_body_bytes' => (int) env('WATCHTOWER_AGENT_HTTP_MAX_BODY_BYTES', 8192),
        'deny_headers' => ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key'],
        'ignore_hosts' => $csv(env('WATCHTOWER_AGENT_HTTP_IGNORE_HOSTS')) ?? [],
        'max_per_execution' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks (wt-webhook-in, wt-http.webhook)
    |--------------------------------------------------------------------------
    |
    | The delivery id is read from the first present header, then from the
    | JSON payload key configured for the source (Stripe: "id"). Duplicates
    | are delivery ids seen within duplicate_ttl seconds.
    |
    */

    'webhooks' => [
        'capture_payload' => (bool) env('WATCHTOWER_AGENT_WEBHOOKS_PAYLOAD', false),
        'max_payload_bytes' => (int) env('WATCHTOWER_AGENT_WEBHOOKS_MAX_PAYLOAD_BYTES', 8192),
        'delivery_headers' => [
            'X-GitHub-Delivery',
            'X-Gitlab-Event-UUID',
            'X-Shopify-Webhook-Id',
            'X-Shopify-Event-Id',
            'svix-id',
            'webhook-id',
            'X-Request-UUID',
            'I-Twilio-Idempotency-Token',
            'X-Event-Id',
        ],
        'delivery_payload_keys' => [
            'stripe' => 'id',
            'paddle' => 'event_id',
            'mailgun' => 'event-data.id',
        ],
        'duplicate_ttl' => (int) env('WATCHTOWER_AGENT_WEBHOOKS_DUPLICATE_TTL', 86400),
        'cache_store' => env('WATCHTOWER_AGENT_WEBHOOKS_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace context
    |--------------------------------------------------------------------------
    |
    | outgoing adds a W3C traceparent (Nightwatch trace id) to Http client
    | requests; incoming turns a received traceparent into wt-trace-link.
    |
    */

    'trace_context' => [
        'outgoing' => (bool) env('WATCHTOWER_AGENT_TRACE_OUTGOING', true),
        'incoming' => (bool) env('WATCHTOWER_AGENT_TRACE_INCOMING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | When true the provider adds the agent's own commands to the schedule.
    |
    */

    'schedule_commands' => (bool) env('WATCHTOWER_AGENT_SCHEDULE_COMMANDS', true),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | "connection:queue" pairs to snapshot. Null means the default connection's
    | default queue. Example: WATCHTOWER_AGENT_QUEUE_LIST=redis:default,redis:mail
    |
    */

    'queues' => $csv(env('WATCHTOWER_AGENT_QUEUE_LIST')),

    'worker_heartbeat_seconds' => (int) env('WATCHTOWER_AGENT_WORKER_HEARTBEAT', 30),

    /*
    |--------------------------------------------------------------------------
    | Output capture
    |--------------------------------------------------------------------------
    |
    | Only the last max_output_bytes are sent. capture_all_task_output calls
    | storeOutput() on every scheduled command, which writes a log file per
    | task under storage/logs.
    |
    */

    'max_output_bytes' => (int) env('WATCHTOWER_AGENT_MAX_OUTPUT_BYTES', 65536),

    'capture_all_task_output' => (bool) env('WATCHTOWER_AGENT_CAPTURE_ALL_TASK_OUTPUT', false),

    'capture_command_output' => (bool) env('WATCHTOWER_AGENT_CAPTURE_COMMAND_OUTPUT', true),

    /*
    |--------------------------------------------------------------------------
    | schedule-catchup
    |--------------------------------------------------------------------------
    |
    | "auto" uses the per-occurrence events of schedule-catchup 1.2 when they
    | exist and falls back to reading its tables (1.1). "events" or "tables"
    | force one path.
    |
    */

    'catchup' => [
        'source' => env('WATCHTOWER_AGENT_CATCHUP_SOURCE', 'auto'),
        'initial_lookback_hours' => (int) env('WATCHTOWER_AGENT_CATCHUP_LOOKBACK_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | Null lists mean "detect": the default database connection, and the
    | "default" Redis connection when the cache, queue or session uses Redis.
    | An empty list turns that check off.
    |
    */

    'health' => [
        'database_connections' => $csv(env('WATCHTOWER_AGENT_HEALTH_DATABASES')),
        'redis_connections' => $csv(env('WATCHTOWER_AGENT_HEALTH_REDIS')),
        'cache' => (bool) env('WATCHTOWER_AGENT_HEALTH_CACHE', true),
        'cache_store' => env('WATCHTOWER_AGENT_HEALTH_CACHE_STORE'),
        'disk_paths' => $csv(env('WATCHTOWER_AGENT_HEALTH_DISK_PATHS')) ?? [storage_path()],
        'disk_degraded_below_percent' => (float) env('WATCHTOWER_AGENT_HEALTH_DISK_DEGRADED', 15),
        'disk_unhealthy_below_percent' => (float) env('WATCHTOWER_AGENT_HEALTH_DISK_UNHEALTHY', 5),
        'queues' => (bool) env('WATCHTOWER_AGENT_HEALTH_QUEUES', true),
        'failed_jobs' => (bool) env('WATCHTOWER_AGENT_HEALTH_FAILED_JOBS', true),
        'failed_jobs_degraded_above' => env('WATCHTOWER_AGENT_HEALTH_FAILED_JOBS_DEGRADED') !== null
            ? (int) env('WATCHTOWER_AGENT_HEALTH_FAILED_JOBS_DEGRADED')
            : null,
        'slow_ms' => (int) env('WATCHTOWER_AGENT_HEALTH_SLOW_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote actions
    |--------------------------------------------------------------------------
    |
    | Pulled from Watch Tower over HTTP and verified with the per-environment
    | action secret. Only "retry_failed_job" is ever executed.
    |
    */

    'actions' => [
        'base_url' => env('NIGHTWATCH_BASE_URL'),
        'token' => env('NIGHTWATCH_TOKEN'),
        'secret' => env('WATCHTOWER_ACTION_SECRET'),
        'timeout' => (int) env('WATCHTOWER_AGENT_ACTIONS_TIMEOUT', 10),
        'cache_store' => env('WATCHTOWER_AGENT_ACTIONS_CACHE_STORE'),
    ],

];
