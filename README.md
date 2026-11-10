# Watch Tower Agent

[![License](https://img.shields.io/github/license/cyberwizard-dev/watchtower-agent.svg?style=flat-square)](LICENSE)

[Laravel Nightwatch](https://nightwatch.laravel.com) records what your app did. Some of what a monitoring dashboard needs isn't in that data: the schedule before a task has ever run, how many jobs are actually waiting, which workers are alive, what a task printed, whether Redis answers from inside the app. Watch Tower, a self-hosted Nightwatch backend, has to estimate those today.

This package sends the missing facts. It installs next to `laravel/nightwatch` and writes extra `wt-*` records through Nightwatch's own agent connection, so there is no second daemon, port or token to manage. Apps without the package keep working exactly as before; Watch Tower falls back to its estimates.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Environment variables](#environment-variables)
- [Features](#features)
- [What Nightwatch can't do, and what this adds](#what-nightwatch-cant-do-and-what-this-adds)
- [Health checks](#health-checks)
- [Output capture](#output-capture)
- [schedule-catchup](#schedule-catchup)
- [Remote actions](#remote-actions)
- [Query bindings (unavailable)](#query-bindings-unavailable)
- [Events and listeners](#events-and-listeners)
- [Broadcasting](#broadcasting)
- [Cache operations](#cache-operations)
- [Mail](#mail)
- [Outgoing HTTP and trace context](#outgoing-http-and-trace-context)
- [Webhooks](#webhooks)
- [Commands](#commands)
- [How records are sent](#how-records-are-sent)
- [Testing](#testing)
- [Changelog](#changelog)
- [License](#license)

## Requirements

- PHP 8.2 or newer
- Laravel 11, 12 or 13
- `laravel/nightwatch` 1.28 or newer, with `php artisan nightwatch:agent` running and pointed at Watch Tower
- The scheduler running (`schedule:run` every minute or `schedule:work`)

## Installation

```bash
composer require cyberwizard/watchtower-agent
php artisan watchtower:hello
```

The service provider is discovered automatically. It registers the commands below and adds them to the schedule, each with `withoutOverlapping()`. `watchtower:hello` confirms the connection and sends the schedule straight away; after that the scheduler keeps everything fresh.

Publish the config if you want to change the defaults:

```bash
php artisan vendor:publish --tag=watchtower-agent-config
```

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `WATCHTOWER_AGENT_ENABLED` | `true` | Master switch. |
| `WATCHTOWER_AGENT_SCHEDULE` | `true` | Schedule registry. |
| `WATCHTOWER_AGENT_QUEUES` | `true` | Queue depth snapshots. |
| `WATCHTOWER_AGENT_WORKERS` | `true` | Worker heartbeats. |
| `WATCHTOWER_AGENT_OUTPUT` | `true` | Task and command output. |
| `WATCHTOWER_AGENT_CATCHUP` | `true` | schedule-catchup replays and outages. |
| `WATCHTOWER_AGENT_HEALTH` | `true` | In-app health reports. |
| `WATCHTOWER_AGENT_ACTIONS` | `false` | Remote actions (retry failed job). |
| `WATCHTOWER_AGENT_BINDINGS` | `false` | Reserved; [unavailable](#query-bindings-unavailable). |
| `WATCHTOWER_AGENT_EVENTS` | `true` | Application events, listeners and queued listener runs. |
| `WATCHTOWER_AGENT_BROADCASTS` | `true` | Broadcast stages. |
| `WATCHTOWER_AGENT_CACHE_OPS` | `true` | Cache flushes, lock flushes, failover (and locks, opt-in). |
| `WATCHTOWER_AGENT_MAIL` | `true` | Mail lifecycle enrichment. |
| `WATCHTOWER_AGENT_HTTP` | `true` | Outgoing HTTP enrichment. |
| `WATCHTOWER_AGENT_WEBHOOKS` | `true` | Incoming webhooks and outgoing webhook tagging. |
| `WATCHTOWER_AGENT_TRACE_CONTEXT` | `true` | W3C `traceparent` out and in. |
| `WATCHTOWER_AGENT_EVENTS_LISTENER_TIMING` | `true` | Instrument the dispatcher to time listeners; `false` records dispatches only. |
| `WATCHTOWER_AGENT_EVENTS_ALLOW` / `_DENY` | none | Comma-separated event name patterns. Allow wins over every deny. |
| `WATCHTOWER_AGENT_EVENTS_SAMPLE_RATE` | `1.0` | Fraction of captured events recorded. |
| `WATCHTOWER_AGENT_EVENTS_MAX_PER_EXECUTION` | `50` | Events per request/job/command/task before a summary replaces them. |
| `WATCHTOWER_AGENT_EVENTS_PAYLOAD` / `_BROADCASTS_PAYLOAD` | `true` | Send the redacted payload (max 8 KB). |
| `WATCHTOWER_AGENT_BROADCASTS_IGNORE_DRIVERS` | `null` | Drivers whose broadcasts are not recorded. |
| `WATCHTOWER_AGENT_CACHE_KEYS` | `masked` | `masked`, `full` or `hidden`. |
| `WATCHTOWER_AGENT_CACHE_LOCKS` | `false` | Report lock acquire/block/release. |
| `WATCHTOWER_AGENT_MAIL_RECIPIENTS` | `masked` | `masked` (`j***@example.com`), `full` or `none`. |
| `WATCHTOWER_AGENT_MAIL_BCC` | `false` | Include Bcc recipients. |
| `WATCHTOWER_AGENT_MAIL_PREVIEW` | `false` | First 500 characters of the text part, redacted. |
| `WATCHTOWER_AGENT_MAIL_CAPTURE_FAILURES` | `true` | Wrap each mailer's transport to see send exceptions. |
| `WATCHTOWER_AGENT_HTTP_HEADERS` | `true` | Sanitised request and response headers. |
| `WATCHTOWER_AGENT_HTTP_BODIES` | `false` | Redacted request and response bodies. |
| `WATCHTOWER_AGENT_HTTP_MAX_BODY_BYTES` | `8192` | Body cap. |
| `WATCHTOWER_AGENT_HTTP_IGNORE_HOSTS` | none | Host patterns never recorded. |
| `WATCHTOWER_AGENT_WEBHOOKS_PAYLOAD` | `false` | Redacted incoming webhook payload. |
| `WATCHTOWER_AGENT_WEBHOOKS_DUPLICATE_TTL` | `86400` | Seconds a delivery id is remembered. |
| `WATCHTOWER_AGENT_WEBHOOKS_CACHE_STORE` | default store | Store for delivery ids. |
| `WATCHTOWER_AGENT_TRACE_OUTGOING` / `_INCOMING` | `true` | Each direction of trace context. |
| `WATCHTOWER_AGENT_REDACT_KEY_PATTERN` | Watch Tower's pattern | Keys whose values are masked in payloads, headers and query strings. |
| `WATCHTOWER_AGENT_SCHEDULE_COMMANDS` | `true` | Add the agent's commands to the schedule. |
| `WATCHTOWER_AGENT_QUEUE_LIST` | default connection's default queue | Comma-separated `connection:queue` pairs to measure. |
| `WATCHTOWER_AGENT_WORKER_HEARTBEAT` | `30` | Seconds between `alive` heartbeats. |
| `WATCHTOWER_AGENT_MAX_OUTPUT_BYTES` | `65536` | Output kept per task or command (the tail). |
| `WATCHTOWER_AGENT_CAPTURE_ALL_TASK_OUTPUT` | `false` | Call `storeOutput()` on every scheduled command. |
| `WATCHTOWER_AGENT_CAPTURE_COMMAND_OUTPUT` | `true` | Capture `Artisan::call()` output. |
| `WATCHTOWER_AGENT_CATCHUP_SOURCE` | `auto` | `auto`, `events` or `tables`. |
| `WATCHTOWER_AGENT_HEALTH_DATABASES` | default connection | Database connections to check. |
| `WATCHTOWER_AGENT_HEALTH_REDIS` | `default` when cache, queue or session use Redis | Redis connections to ping. |
| `WATCHTOWER_AGENT_HEALTH_DISK_PATHS` | `storage_path()` | Paths whose disk free space is reported. |
| `WATCHTOWER_AGENT_HEALTH_DISK_DEGRADED` / `_UNHEALTHY` | `15` / `5` | Free-space percentages. |
| `WATCHTOWER_AGENT_HEALTH_FAILED_JOBS_DEGRADED` | none | Failed job count above which the check is degraded. |
| `WATCHTOWER_AGENT_HEALTH_SLOW_MS` | `1000` | Database, Redis and cache checks slower than this are degraded. |
| `NIGHTWATCH_BASE_URL` | none | Watch Tower URL, for remote actions. |
| `NIGHTWATCH_TOKEN` | none | Environment token (already set for Nightwatch). |
| `WATCHTOWER_ACTION_SECRET` | none | Per-environment action secret from Watch Tower settings. |

`NIGHTWATCH_DEPLOY` and `NIGHTWATCH_SERVER` are read from Nightwatch's config, so agent records carry the same deploy and server as Nightwatch's.

## Features

| Feature | Record | When |
|---|---|---|
| `schedule` | `wt-schedule` | Every 10 minutes and on `watchtower:hello`. Every scheduled event with cron, timezone, flags, description, next run and whether it replays on catch-up. |
| `queues` | `wt-queue-snapshot` | Every minute. Size, pending, delayed, reserved and oldest pending job per connection and queue, plus the failed job count. A measure the driver can't provide is `null`. |
| `workers` | `wt-worker` | `starting` when `queue:work` starts, `alive` at most every 30 seconds while it loops, `stopping` with the reason, jobs processed and memory. |
| `output` | `wt-output` | After a scheduled task whose output goes to a file, and after `Artisan::call()`. |
| `catchup` | `wt-catchup`, `wt-outage` | When cyberwizard/schedule-catchup replays a missed occurrence or records an outage. |
| `health` | `wt-health` | Every minute. Built-in and application checks. |
| `actions` | `wt-action-result` | Every minute, when enabled. |
| `events` | `wt-event`, `wt-event-summary`, `wt-listener-run` | Each application event dispatched; each queued listener run by a worker. |
| `broadcasts` | `wt-broadcast` | When a broadcast is queued, accepted by a driver or fails. |
| `cache_ops` | `wt-cache-op` | Store flush, lock flush, failover; lock acquire/block/release when enabled. |
| `mail` | `wt-mail` | Queued, sending, sent (accepted by the transport) and failed. |
| `http` | `wt-http` | Every outgoing request attempt (Laravel Http client and Guzzle with the middleware). |
| `webhooks` | `wt-webhook-in`, `wt-http.webhook` | Each request through `watchtower.webhook:{source}`; outgoing webhooks tagged. |
| `trace_context` | `wt-trace-link` | A request that arrives with a `traceparent`. |
| — | `wt-hello` | Hourly and on demand: package, Laravel and PHP versions and the enabled features. |

Every record has `v: 1`, `t`, `timestamp`, `deploy`, `server` and `agent_version`. Records about what happened inside a request, job, command or scheduled task (events, broadcasts, cache operations, mail, HTTP, webhooks, trace links) also carry Nightwatch's `trace_id`, `execution_source`, `execution_id`, `execution_stage` and `user`; they are buffered with the execution, so they follow Nightwatch's sampling and arrive with its own records. Each type is capped per execution (events 50, others 100–200) so Nightwatch's 500-record buffer is never flooded. Task records carry the same group hash Nightwatch computes for the task, so Watch Tower attaches them to the task Nightwatch already knows.

## What Nightwatch can't do, and what this adds

| Nightwatch alone | With the agent |
|---|---|
| A scheduled task appears only after its first run. | The whole schedule is known up front, so a task that never ran is visible and "overdue" is computed from the real cron. |
| Queue depth is estimated from queued minus processed jobs. | Real `size()` / `pendingSize()` / `delayedSize()` / `reservedSize()` from the queue driver. |
| Workers are inferred from servers that processed jobs. | Each worker reports itself, so an idle worker still shows as alive and a dead one goes stale after two minutes. |
| Task and command output isn't recorded. | The tail of the output is attached to the run. |
| A slot missed during downtime looks missed forever. | Replays by schedule-catchup mark the slot recovered; outages and give-ups are visible. |
| Health is probed from outside. | Database, Redis, cache, disk and queues are checked from inside the app. |
| Retrying a failed job means SSH and `queue:retry`. | One click in Watch Tower, verified and run by the agent. |

## Health checks

Built in:

- `database:{connection}`: `select 1`, timed.
- `redis:{connection}`: `PING`, timed.
- `cache:{store}`: put, get and forget of a random key.
- `disk:{path}`: free space in percent.
- `queue:{connection}/{queue}`: the connection answers `size()`.
- `failed-jobs`: count from the failed job provider, when it can be counted.

Register your own in a service provider's `boot()`:

```php
use Cyberwizard\WatchtowerAgent\Health\CheckResult;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;

WatchtowerAgent::check('payments-api', function (): CheckResult {
    $started = microtime(true);
    $ok = Http::timeout(3)->get('https://payments.example.com/health')->successful();
    $ms = (microtime(true) - $started) * 1000;

    return match (true) {
        ! $ok => CheckResult::unhealthy('Payments API is down'),
        $ms > 1000 => CheckResult::degraded('Payments API is slow', $ms, 'ms'),
        default => CheckResult::healthy('OK', $ms, 'ms'),
    };
});
```

A check may also return `true` or `false`. One that throws is reported as unhealthy with the exception message. Watch Tower turns reported checks into monitored health checks when you choose "Monitor this".

## Output capture

Laravel discards scheduled task output unless you ask for it. The agent sends output only when it was written somewhere it can read:

- **Scheduled commands** with `->storeOutput()`, `->sendOutputTo()` or `->appendOutputTo()`. The last `max_output_bytes` of the file are sent after the task finishes (after `schedule:finish` for background tasks). With `appendOutputTo()` the tail may include earlier runs.
- **Every scheduled command** when `WATCHTOWER_AGENT_CAPTURE_ALL_TASK_OUTPUT=true`. The agent calls `storeOutput()` just before each command runs, which writes one file per task under `storage/logs/schedule-*.log`, overwritten on each run.
- **Commands run with `Artisan::call()`**, from their `BufferedOutput`. The buffer is read without being consumed, so `Artisan::output()` still works.

**Terminal output can't be captured.** A command run from a shell writes straight to the console, and nothing can read that back. Closure tasks (`Schedule::call()`) have no process output either. ANSI colour codes are removed and invalid UTF-8 is replaced before sending.

## schedule-catchup

With [cyberwizard/schedule-catchup](https://github.com/cyberwizard-dev/schedule-catchup) installed, replays and outages are reported:

- **1.2 and newer:** the agent listens to `ScheduleOccurrenceReplayed`, `ScheduleOccurrenceFailed`, `ScheduleOccurrenceDeferred` and `ScheduleOutageRecorded`. Records include the replay's runtime and, when the task's output is captured, its output.
- **1.1:** those events don't exist, so after each catch-up run the agent reads new rows from `cyber_schedule_catchup_logs` and `cyber_schedule_catchup_outages`, keeping a cursor in the cache. On the first run it looks back 24 hours (`WATCHTOWER_AGENT_CATCHUP_LOOKBACK_HOURS`). Runtime and output aren't available on this path, and rows for events no longer in the schedule are skipped.

`replayOnCatchup()` events are flagged in the schedule registry, so Watch Tower can show them as "catching up" rather than overdue after downtime.

## Remote actions

Off by default. When enabled, `watchtower:actions` runs every minute and asks Watch Tower for pending actions:

```env
WATCHTOWER_AGENT_ACTIONS=true
NIGHTWATCH_BASE_URL=https://watchtower.example.com
WATCHTOWER_ACTION_SECRET=the-secret-shown-in-watch-tower-settings
```

Each action is checked before anything runs:

1. Its HMAC-SHA256 signature over `id|type|payload|issued_at|expires_at` must match `WATCHTOWER_ACTION_SECRET` (compared with `hash_equals`).
2. It must not have expired.
3. Its type must be on the allow-list, which has one entry: `retry_failed_job`.
4. Its id must not have been seen before. Ids are claimed atomically in the cache, so a replayed action is ignored.

`retry_failed_job` checks the failed job exists, then runs `queue:retry` for that id. The result goes back to Watch Tower over HTTP and as a `wt-action-result` record. The agent only ever pulls; Watch Tower never connects to your app.

## Query bindings (unavailable)

The `bindings` flag is reserved, and the agent never reports it as enabled. It would attach the real parameter values of slow queries to Nightwatch's query records, but Nightwatch 1.x gives no reliable way to tie the two together:

- The `redactQueries()` callback receives a `Laravel\Nightwatch\Records\Query` with only `sql`, `file`, `line`, `duration`, `connection` and `connectionType`. It never sees the bindings or the `QueryExecuted` event.
- A query record has no id of its own. Its `_group` and `trace_id` are the same for every run of the same statement in a request, so the third `select * from users where id = ?` can't be told from the first.
- Its `timestamp` is taken from Nightwatch's clock when the record is built, not from anything an outside listener can see.
- `_group` is hashed from the SQL after redaction callbacks run, so an app that rewrites SQL changes the key in ways a listener can't predict.

Matching by order within a trace breaks as soon as a `rejectQueries()` callback drops a query. Watch Tower keeps its "bindings are not captured" note until Nightwatch exposes a key. `tests/Feature/BindingsSpikeTest.php` pins each of these facts down.

## Events and listeners

Every application event that passes the filters becomes a `wt-event`: name, class, a redacted payload (public properties; models become `{class, key}`; keys matching the redaction pattern are masked; at most 8 KB, with a `truncated` flag), whether it broadcasts, and each listener with its duration, status (`ok`, `failed`, `queued`) and exception. Framework and package events (`Illuminate\*`, `Laravel\*`, the agent's own namespace, `eloquent.*`, `bootstrapped:*`, `bootstrapping:*`, `creating:*`, `composing:*`) are skipped unless an allow pattern names them. Past `max_per_execution` events, one `wt-event-summary` per execution counts what was dropped, by name. Events the agent dispatches itself are never recorded.

Listener timing needs a hook inside the dispatcher. The agent replaces the container's `events` instance with `InstrumentedDispatcher`, a subclass of Laravel's dispatcher whose every property is bound **by reference** to the original's, so the two never diverge: a listener registered through an old reference (the router, the console kernel, Nightwatch) is seen by the new one and vice versa. Its only changes are a frame around `invokeListeners()` and a timing wrapper from `makeListener()` that keeps the raw listener in the closure's `listener` static variable. `tests/Feature/EventDispatcherSpikeTest.php` proves it coexists with Nightwatch's listeners, Telescope-style `listen('*')` watchers that reflect `getListeners()`, `Event::fake()` and `Event::assertListening()`, `ShouldQueue` listeners, subscribers, `Event::until()`, after-commit and deferred events, and nested dispatches.

Limits: events dispatched through a reference captured before the agent registered (mostly framework events, which are skipped anyway) are not timed. If another package has already replaced the dispatcher with its own class, or `WATCHTOWER_AGENT_EVENTS_LISTENER_TIMING=false`, the agent falls back to a wildcard listener: dispatches are recorded with an empty listener list and a `duration` of 0.

Queued listeners are recorded as `queued`. The event id and listener name travel in hidden Context with the `CallQueuedListener` job, and the worker emits `wt-listener-run` (status `ok`, `failed` or `released`, attempt, duration, Nightwatch job id) when it finishes. Without that context (older jobs, encrypted jobs) the listener and event are read from the job payload and `event_id` is null.

## Broadcasting

Each broadcast connection is decorated (`Broadcast::extend()` per configured driver, wrapping any creator the app registered, re-applied once the app has booted). `wt-broadcast` records:

- `queued` when a `BroadcastEvent` job is pushed (with the Nightwatch job id),
- `sent` when the driver's `broadcast()` returned,
- `failed` when it threw, or when a queued broadcast job failed before reaching a driver.

All stages of one broadcast share its `broadcast_id` (carried in the job payload) and the `event_id` of the event that caused it. Channels are listed with their type (public, private, presence), `socket_excluded` says whether `toOthers()` was used, and the payload is redacted and capped. **"Sent" means the driver accepted the publish call; the agent never claims delivery to clients**, because no driver reports per-message receipt. The `null` driver is ignored by default.

## Cache operations

Nightwatch already records hits, misses, writes and deletes. `wt-cache-op` adds the rest Laravel exposes:

| Op | Source | Laravel |
|---|---|---|
| `flush`, `flush-failure` | `CacheFlushing` / `CacheFlushed` / `CacheFlushFailed` | 12.x+ |
| `locks-flush` | `CacheLocksFlushing` / `CacheLocksFlushed` / `CacheLocksFlushFailed` (`error` set on failure) | 12.x+ |
| `failover` | `CacheFailedOver` from the failover store | 13.x |
| `lock-acquired`, `lock-blocked`, `lock-released` | `Cache::lock()` decorated, when `WATCHTOWER_AGENT_CACHE_LOCKS=true` | all |

`Cache::flush()` goes straight to the store and fires no event, while `Cache::clear()` does the same flush with events; the agent adds a `flush` macro on the cache repository that calls `clear()`, so `Cache::flush()` and `artisan cache:clear` are recorded. Lock instrumentation is opt-in because `Cache::lock()` then returns a decorator (implementing the `Lock` contract and forwarding every other method) instead of the store's own lock class; locks taken directly from a store (`Cache::getStore()->lock()`, the scheduler's overlap mutex) are not seen. Increments, decrements and expirations fire no Laravel event and can't be observed. Keys are masked by default (`user:123:profile` → `user:*:profile`).

## Mail

Each message gets an `X-Watchtower-Mail-Id` header. `wt-mail` records `queued` (a `SendQueuedMailable` or mail notification pushed), `sending` (`MessageSending`), `sent` (`MessageSent`, with the transport's Message-ID, which provider delivery webhooks match on) and `failed` (the transport threw; the agent wraps each mailer's Symfony transport for this because Laravel dispatches no failure event). Stages of a queued mail share its mail id. Recipients are masked by default, Bcc is left out unless enabled, and the preview is off. **"Sent" means accepted by the transport, not delivered**; only provider events received by Watch Tower mark a mail delivered or bounced. Nightwatch's own `mail` record is untouched; `wt-mail` only fills gaps.

## Outgoing HTTP and trace context

A Guzzle middleware is added to Laravel's Http client (`Http::globalMiddleware()`), after Nightwatch's. For every attempt it records `wt-http`: method, URL with sensitive query values masked and credentials removed, host, normalised path (`/v1/charges/{id}`), a group hash of `xxh128(METHOD . host . normalised path)`, status or outcome (`response`, `connection-failed`, `timeout`), duration, configured timeouts, sanitised headers (`authorization`, `proxy-authorization`, `cookie`, `set-cookie`, `x-api-key`, any `*signature*` header and anything matching the redaction pattern become `[redacted]`) and, only when enabled, redacted bodies capped at 8 KB. The middleware is used instead of the `RequestSending` / `ResponseReceived` / `ConnectionFailed` events because it sees each attempt with its options and timing, can add headers, and covers plain Guzzle clients too; using both would record every request twice. Nightwatch's `outgoing-request` record is unchanged.

Retries are numbered by repetition: the same method and URL sent again right after a failed attempt in the same execution is attempt 2, 3, and so on.

For Guzzle clients built by hand, push the middleware after Nightwatch's:

```php
$stack = HandlerStack::create();
$stack->push(Nightwatch::guzzleMiddleware());
$stack->push(WatchtowerAgent::guzzleMiddleware());
$client = new Client(['handler' => $stack]);
```

Outgoing requests get a W3C `traceparent` header (`00-{Nightwatch trace id without dashes}-{random span}-01`) unless they already have one. A request arriving with a valid `traceparent` produces `wt-trace-link` (`parent_trace_id`, `parent_span_id`), so Watch Tower can join executions across monitored apps. The agent's own calls to Watch Tower (remote actions) carry neither and are never recorded.

## Webhooks

Incoming: add the route middleware with a source name, and report the signature check from your handler:

```php
Route::post('/webhooks/stripe', StripeWebhookController::class)->middleware('watchtower.webhook:stripe');

// in the controller, after verifying
WatchtowerAgent::webhookSignature($valid ? 'valid' : 'invalid'); // or 'missing'; default 'unchecked'
```

`wt-webhook-in` records the endpoint, method, signature result, status (`processed` for 2xx/3xx, `rejected` for 4xx, `failed` for 5xx or an exception), HTTP status, duration, delivery id and a `duplicate` flag. The delivery id comes from the first present header in `webhooks.delivery_headers` (`X-GitHub-Delivery`, `X-Gitlab-Event-UUID`, `X-Shopify-Webhook-Id`, `svix-id`, `webhook-id`, ...) or from a payload key per source (`stripe` → `id`, `paddle` → `event_id`, `mailgun` → `event-data.id`). Delivery ids are remembered at the cache store level (no cache events) for `duplicate_ttl` seconds. The payload is off by default.

Outgoing: tag a Laravel Http request as a webhook delivery and its `wt-http` records carry `webhook: {event, destination}`:

```php
Http::withWatchtowerWebhook('order.paid', 'acme-erp')->post($url, $payload);
```

With spatie/laravel-webhook-server installed, each `WebhookCallSucceededEvent` and `WebhookCallFailedEvent` becomes a tagged `wt-http` (attempt, status, timing from the transfer stats; the event name from `meta.event`, the payload's `event` or `type`). Spatie sends with its own Guzzle client, so these don't duplicate the Http middleware.

## Commands

| Command | Schedule |
|---|---|
| `watchtower:hello` | hourly |
| `watchtower:schedule-sync` | every 10 minutes |
| `watchtower:queue-snapshot` | every minute |
| `watchtower:health-report` | every minute (`-v` prints the results) |
| `watchtower:actions` | every minute, only when actions are enabled |

Set `WATCHTOWER_AGENT_SCHEDULE_COMMANDS=false` to schedule them yourself.

## How records are sent

Records go through Nightwatch's existing connection to its local agent, which forwards them to Watch Tower with everything else. Records produced outside a request, command or job (the registry, snapshots, heartbeats, health, catch-up, outages, action results) are sent immediately with Nightwatch's `writeNow()`, because Nightwatch has already finished with the current execution when the scheduler or worker events fire, and anything buffered then is dropped. In-execution records are buffered with `write()` and flushed by Nightwatch with the execution; per-execution summaries are emitted before Nightwatch digests (request terminating, `CommandFinished`, `ScheduledTaskFinished`/`Failed`, the end of each job).

All of this sits in one class, `Cyberwizard\WatchtowerAgent\Transport`, which is also the only class that reads Nightwatch's execution state for the execution header. It does nothing when Nightwatch isn't installed, is disabled or is paused, and it never throws. A recursion guard keeps the agent from observing itself. Listeners catch their own errors and hand them to `report()`, so the agent can't break a request, a job or the scheduler. Records hold only scalars and arrays.

Nightwatch's ingest API is marked internal. If a Nightwatch release changes it, `Transport` is the only file that needs to change.

## Testing

```bash
composer install
composer test
```

The suite runs on Orchestra Testbench with in-memory SQLite. Nightwatch's ingest is replaced with an in-memory fake, and tests swap `Cyberwizard\WatchtowerAgent\Contracts\RecordTransport` for a fake when they only care about the records produced. Development uses a local checkout of schedule-catchup at `../schedule-catchup` through a Composer path repository.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
