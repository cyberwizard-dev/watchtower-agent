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
- [Authentication events](#authentication-events)
- [Shield (bot and spam protection)](#shield-bot-and-spam-protection)
- [Browser monitoring](#browser-monitoring)
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
| `WATCHTOWER_AGENT_AUTH` | `true` | Authentication events (security signals). |
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
| `WATCHTOWER_AGENT_AUTH_IDENTIFIER` | `masked` | Attempted login identifier: `masked` (`j***@example.com`), `full` or `none`. |
| `WATCHTOWER_AGENT_AUTH_IDENTIFIER_KEYS` | `email,username,login,phone` | Credential / request keys the identifier is read from. Nothing else is read. |
| `WATCHTOWER_AGENT_AUTH_THROTTLE_SECONDS` | `10` | At most one `failed`/`lockout` record per event, IP and identifier in this window (`0` = every one). |
| `WATCHTOWER_AGENT_SHIELD` | `false` | Shield: bot and spam protection from rules managed in Watch Tower. |
| `WATCHTOWER_AGENT_SHIELD_MODE` | rules' mode | `monitor` or `enforce`, overriding the mode Watch Tower sends. |
| `WATCHTOWER_AGENT_SHIELD_THROTTLE_SECONDS` | `10` | At most one `wt-shield` record per action, rule and IP in this window (`0` = every one). |
| `WATCHTOWER_AGENT_SHIELD_SAMPLE_DECISIONS` | `true` | A decision that is sent also makes Nightwatch keep the request it was made for, whatever the request sample rate. |
| `WATCHTOWER_AGENT_SHIELD_TIMEOUT` | `2` | Seconds for the rules pull (connect and total). |
| `WATCHTOWER_AGENT_SHIELD_CACHE_STORE` | default store | Store for the rules, rate limit counters and penalties. Use a shared store (Redis) with several servers. |
| `WATCHTOWER_AGENT_SHIELD_PASS_MINUTES` | `30` | Lifetime of the `wt_shield_pass` cookie after a solved challenge. |
| `WATCHTOWER_AGENT_SHIELD_HONEYPOT_FIELD` | `wt_hp_website` | Honeypot field name until Watch Tower sends one. |
| `WATCHTOWER_AGENT_SHIELD_UNREACHABLE_GRACE` | `900` | Seconds `watchtower:shield-sync` tolerates Watch Tower being unreachable (with cached rules) before the task fails. |
| `WATCHTOWER_AGENT_BROWSER` | `true` | Browser monitoring; active once a browser key is set. |
| `WATCHTOWER_AGENT_BROWSER_KEY` | — | Public browser key from Watch Tower (Settings → Environments → Browser monitoring). |
| `WATCHTOWER_AGENT_BROWSER_PROXY` | `true` | Serve the script and receive beacons on the app's own domain (see [Browser monitoring](#browser-monitoring)). |
| `WATCHTOWER_AGENT_BROWSER_PATH` | `/_t` | Path prefix of the proxy routes. |
| `WATCHTOWER_AGENT_BROWSER_RELEASE` | `NIGHTWATCH_DEPLOY` | `data-release` of the script, to compare releases. |
| `WATCHTOWER_AGENT_BROWSER_SAMPLE` | — | Share of page loads to report, e.g. `0.25`. |
| `WATCHTOWER_AGENT_BROWSER_BEACONS_PER_MINUTE` | `120` | Beacons accepted per visitor IP per minute by the proxy. |
| `TURNSTILE_SECRET` | none | Cloudflare Turnstile secret. Without it challenges become 429s. Never sent by Watch Tower. |
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
| `auth` | `wt-auth` | Login, failed login, lockout, logout, password reset, registration, e-mail verification, other-device logout. |
| `shield` (off by default) | `wt-shield` | Every Shield decision that is not "allow". |
| — | `wt-hello` | Hourly and on demand: package, Laravel and PHP versions and the enabled features. |

Every record has `v: 1`, `t`, `timestamp`, `deploy`, `server` and `agent_version`. Records about what happened inside a request, job, command or scheduled task (events, broadcasts, cache operations, mail, HTTP, webhooks, trace links, auth events) also carry Nightwatch's `trace_id`, `execution_source`, `execution_id`, `execution_stage` and `user`; they are buffered with the execution, so they follow Nightwatch's sampling and arrive with its own records (except Shield decisions and failed logins/lockouts, which are sent immediately). Each type is capped per execution (events 50, others 100–200) so Nightwatch's 500-record buffer is never flooded. Task records carry the same group hash Nightwatch computes for the task, so Watch Tower attaches them to the task Nightwatch already knows.

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

## Authentication events

`wt-auth` turns Laravel's auth events into security signals. They carry the execution header of the request or job that raised them; failed logins and lockouts are sent immediately (see [Security signals and sampling](#security-signals-and-sampling)), the other events are buffered with the execution:

| `event` | Source |
|---|---|
| `login` | `Illuminate\Auth\Events\Login` (with `remember`) |
| `failed` | `Failed` |
| `lockout` | `Lockout` (the login throttle tripped) |
| `logout` | `Logout` |
| `password_reset` | `PasswordReset` |
| `registered` | `Registered` |
| `verified` | `Verified` |
| `other_device_logout` | `OtherDeviceLogout` |

Each record has the `guard` (when the event has one), the `user_id` (`getAuthIdentifier()` of the event's user, including the user a failed attempt matched), the client `ip` and `user_agent` (at most 255 characters) and, for failed logins and lockouts, the attempted `identifier`. IP and user agent come from the current request only when Nightwatch is capturing an HTTP request; a lockout uses the request it carries. The IP is `$request->ip()`, so configure trusted proxies or every login comes from your load balancer.

**The password is never read.** For `Failed` the agent looks only at the keys in `auth.identifier_keys` (default `email`, `username`, `login`, `phone`) in the credentials array, and for `Lockout` only at those keys in the request input; any configured key that matches the redaction pattern (`password`, `secret`, `token`, ...) is ignored. The first non-empty value is sent masked like mail recipients (`j***@example.com`, `j***` for a username), in full with `WATCHTOWER_AGENT_AUTH_IDENTIFIER=full`, or not at all with `none`. Auth records are capped at 100 per execution.

## Shield (bot and spam protection)

Off by default (`WATCHTOWER_AGENT_SHIELD=true`). Shield is a global middleware that applies rules you manage in Watch Tower before the app sees the request. It runs right after `TrustProxies` (and Nightwatch's own middleware), so `$request->ip()` is the client and the request is still captured by Nightwatch.

**Rules.** `watchtower:shield-sync` pulls `GET {NIGHTWATCH_BASE_URL}/api/shield/v1/rules` with `NIGHTWATCH_TOKEN` every minute (2 s timeout, `If-None-Match` so unchanged rules are a 304) into the app cache. The copy is used for its `ttl_seconds`; a stale or missing copy is refreshed **after** the response is sent, never during a request, and at most once per 15 seconds across processes. The last good copy is kept when Watch Tower is unreachable; the sync command only fails (and so shows up as a failed scheduled task) once that copy is older than `WATCHTOWER_AGENT_SHIELD_UNREACHABLE_GRACE` (15 minutes) or there is none, so a Watch Tower restart is not an error. With no copy at all every request is allowed: Shield fails open, and an error inside Shield also lets the request through.

**Order.** Allow list (wins over everything) → blocked IPs (IPv4/IPv6 CIDR, expired entries ignored) → user agent expressions (PCRE without delimiters; invalid expressions are skipped, matching is capped at 1 KB and a low backtracking limit) → path globs (`*` matches across `/`, case-insensitive, on the decoded path with duplicate slashes collapsed) → rate limits → honeypot → challenge.

**Rate limits** count matching requests (methods, path globs) in a fixed window per `by`: `ip`, `ip_path`, or `user`. Shield runs before sessions start, so `user` is only known for guards that need no session (API tokens); otherwise it counts by IP. Past the limit the client gets 429 with `Retry-After`, for `block_seconds` when set.

**Responses.** 403 (`Forbidden.`) for blocks, 429 (`Too Many Requests.`) with `Retry-After` for rate limits; JSON (`{"message": ...}`) when the client wants JSON. Bodies never name the rule.

**Challenge.** A rate limit with `action: challenge` shows a browser GET (an `Accept` with `text/html`, not XHR) a Turnstile interstitial (status 429, `Cache-Control: no-store`). The widget posts to `POST /_watchtower/shield/verify`, which checks the token with Cloudflare's siteverify using `TURNSTILE_SECRET` from your env, sets the `wt_shield_pass` cookie (HMAC of expiry and client IP with the app key, 30 minutes, HttpOnly, SameSite=Lax) and redirects back to the same-site path. While the cookie is valid, challenge limits let the client through. JSON and other non-browser requests, or an app without `TURNSTILE_SECRET`, get the 429 instead. The verify route allows 20 attempts per IP per minute; an unreachable siteverify counts as a failed check.

**Honeypot.** Put `@watchtowerHoneypot` (or `{!! \Cyberwizard\WatchtowerAgent\WatchtowerAgent::honeypotField() !!}`) in your forms. It renders a text input moved off screen, `aria-hidden`, `tabindex="-1"` and `autocomplete="off"`, named after the rules' `honeypot.field`. A POST with that field filled is blocked (`action: block`) or only reported (`action: flag`).

**Monitor mode** blocks nothing: what would have been blocked, rate limited or challenged is reported as `would_block`. `WATCHTOWER_AGENT_SHIELD_MODE` overrides the mode Watch Tower sends.

Each decision that is not "allow" is one `wt-shield` record (`blocked`, `rate_limited`, `challenged`, `challenge_passed`, `challenge_failed`, `honeypot`, `would_block`) with the rule (`ip:198.51.100.7/32`, `ua`, `path:/.env`, `rate:login`, `honeypot`, `challenge`), IP, method, path, user agent, user id (only when a guard already resolved one) and the rules version. The rules pull and siteverify calls are the agent's own: no `wt-http`, no traceparent, and the cache bookkeeping is invisible to Nightwatch's cache sensor. Decisions are sent immediately and throttled, see below.

### Security signals and sampling

Nightwatch drops the buffered records of every execution its sample rate skips (`NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1` keeps one request in ten). A security signal must not depend on that, so `wt-shield` decisions and `wt-auth` `failed`/`lockout` records are sent immediately with `writeNow()`, still carrying the execution header and still capped per execution. A buffered record would only arrive for sampled requests.

So a client hammering the app doesn't cost one write per request, repeats are throttled: at most one `wt-shield` record per (action, rule, IP) and one `failed`/`lockout` record per (event, IP, identifier) every 10 seconds (`WATCHTOWER_AGENT_SHIELD_THROTTLE_SECONDS`, `WATCHTOWER_AGENT_AUTH_THROTTLE_SECONDS`, `0` sends every one). The window is kept in the cache (the Shield cache store for decisions, the default store for auth), so it is shared by all PHP-FPM processes, and in memory for long-running ones. Records carry no count, so during an attack Watch Tower sees one decision per window and its rule hit counters count windows, not requests.

When a decision is sent, Shield also calls Nightwatch's `sample()` so the request it was made for is kept (its request record, and anything else Nightwatch buffered); throttled decisions don't, so this adds at most one sampled request per window. Turn it off with `WATCHTOWER_AGENT_SHIELD_SAMPLE_DECISIONS=false`.

## Browser monitoring

Page views, Core Web Vitals (LCP, INP, CLS, FCP, TTFB) and JavaScript errors from your visitors'
browsers, shown on Watch Tower's **Frontend** page. Turn it on for the environment in Watch Tower
(**Settings → Environments → Browser monitoring**), list your site under allowed sites, then:

```env
WATCHTOWER_AGENT_BROWSER_KEY=<browser key from Watch Tower>
```

```blade
{{-- in your layout's <head> --}}
@watchtowerBrowser
```

That is all: nothing renders until a browser key and `NIGHTWATCH_BASE_URL` are set.

**Why a proxy.** Many content blockers and privacy browsers drop requests to third-party monitoring
hosts (`net::ERR_BLOCKED_BY_CLIENT`). So by default the script is served from your own domain at
`/_t/s.js` and beacons go to `/_t/e`; the agent forwards them to Watch Tower with `NIGHTWATCH_TOKEN` and
the visitor's origin, user agent and IP (`X-Watchtower-*` headers, which Watch Tower only trusts with
that environment's token). The token never reaches the browser. Both routes sit outside the `web`
middleware group (no session, no CSRF), are rate limited per visitor IP, cache the script for an hour
and never show visitors an error, even when Watch Tower is down. The agent's own calls produce no
`wt-http` and no `traceparent`. With `WATCHTOWER_AGENT_BROWSER_PROXY=false` the tag loads the script
from Watch Tower directly. Behind a load balancer, make sure Laravel's `TrustProxies` is configured so
`$request->ip()` is the visitor.

## Commands

| Command | Schedule |
|---|---|
| `watchtower:hello` | hourly |
| `watchtower:schedule-sync` | every 10 minutes |
| `watchtower:queue-snapshot` | every minute |
| `watchtower:health-report` | every minute (`-v` prints the results) |
| `watchtower:actions` | every minute, only when actions are enabled |
| `watchtower:shield-sync` | every minute, only when Shield is enabled |

Set `WATCHTOWER_AGENT_SCHEDULE_COMMANDS=false` to schedule them yourself.

## How records are sent

Records go through Nightwatch's existing connection to its local agent, which forwards them to Watch Tower with everything else. Records produced outside a request, command or job (the registry, snapshots, heartbeats, health, catch-up, outages, action results) are sent immediately with Nightwatch's `writeNow()`, because Nightwatch has already finished with the current execution when the scheduler or worker events fire, and anything buffered then is dropped. In-execution records are buffered with `write()` and flushed by Nightwatch with the execution (Shield decisions and failed logins/lockouts are the exception, see [Security signals and sampling](#security-signals-and-sampling)); per-execution summaries are emitted before Nightwatch digests (request terminating, `CommandFinished`, `ScheduledTaskFinished`/`Failed`, the end of each job).

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
