# Changelog

Notable changes to `cyberwizard/watchtower-agent` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.7.1] - 2026-11-13

### Added

- The settings sync tells Watch Tower the app's own sample rates (`X-Watchtower-App-Sampling`: its `NIGHTWATCH_*_SAMPLE_RATE` values and `WATCHTOWER_AGENT_BROWSER_SAMPLE`), so **Settings → Sampling** shows the rate in effect where Watch Tower sets none.

## [0.7.0] - 2026-11-13

### Added

- Sample rates set in Watch Tower (`settings`, on by default, `WATCHTOWER_AGENT_SETTINGS`): the new `watchtower:settings-sync` command (scheduled `everyFiveMinutes()->withoutOverlapping(5)`) pulls `GET /api/agent/v1/settings` with the environment token (ETag / 304) and keeps the last good copy in the cache (`WATCHTOWER_AGENT_SETTINGS_CACHE_STORE`, `WATCHTOWER_AGENT_SETTINGS_TIMEOUT`). The request, command, exception and scheduled-task rates it carries are applied to Nightwatch at boot, before each Octane request and on every queue worker loop, reading the cache at most once a minute per process. A rate left unset in Watch Tower keeps the app's own `NIGHTWATCH_*_SAMPLE_RATE`, and clearing one restores it. A failed sync keeps the last good settings. The call is never recorded.
- `wt-hello` lists `settings` when enabled.

## [0.6.1] - 2026-11-13

### Fixed

- The agent's own calls to Watch Tower (Shield rules sync, action pulls, browser and replay proxying) no longer show up as the host app's outgoing requests. `Guard::quietly()` now also pauses Nightwatch while it runs (through `Transport::ignoring()`), so Nightwatch's outgoing-request sensor doesn't record them either. Previously each app logged about two outgoing requests a minute to its own Watch Tower.

## [0.6.0] - 2026-11-12

### Added

- `wt-host` (`host`, on by default, `WATCHTOWER_AGENT_HOST`): server metrics sent every minute by the new `watchtower:host-snapshot` command (scheduled `everyMinute()->withoutOverlapping(5)`). CPU cores, load and usage (two `/proc/stat` samples 500 ms apart), memory and swap, the container memory limit and usage from cgroup v2 or v1 when limited, disk usage for `/` and `storage_path()` (`WATCHTOWER_AGENT_HOST_DISKS`, deduplicated by file system, at most 10), uptime, hostname, OS and the process count with the top 15 processes (top by CPU and by memory, sorted by CPU) with user, name, command line, CPU %, memory and start time. Read from `/proc` on Linux; macOS falls back to `sysctl`, `vm_stat` and `ps`. Unreadable values are `null`; the command never fails on a read.
- `WATCHTOWER_AGENT_HOST_PROC` points the agent at a read-only mount of the host's `/proc` (e.g. `/proc:/host/proc:ro`) to report every process on the server (`"scope": "host"`) instead of the container's. Off by default because other processes' command lines become visible; commands are capped at 300 characters and secret-looking arguments (`--password=…`, `--token=…`, mysql `-p…`, `*KEY=`/`*SECRET=`/`*TOKEN=`/`*PASSWORD=` assignments, URL passwords) are replaced with `[redacted]`. `WATCHTOWER_AGENT_HOST_PASSWD` maps uids to user names from the host's `/etc/passwd`.
- `wt-hello` lists `host` when enabled.

## [0.5.0] - 2026-11-12

### Added

- Session replay through the first-party proxy: `GET {path}/r.js` serves Watch Tower's recorder (cached like the script), `GET {path}/c` forwards the replay settings request (cached for a minute; `{"replay":null}` when Watch Tower is unreachable or too old) and `POST {path}/r` forwards a recorded chunk (up to 1 MB, rate limited per IP with `WATCHTOWER_AGENT_BROWSER_REPLAY_CHUNKS_PER_MINUTE`, 30). Watch Tower's 4xx answers to a chunk are passed on so the recorder stops. Needs Watch Tower with session replay.
- `wt-notification` (`notifications`, on by default): one in-execution record per recipient and channel from `NotificationSent` and `NotificationFailed`, with the notification id, class and channel, the recipient's type, class, key and label (`notifications.label_attribute` / `WATCHTOWER_AGENT_NOTIFICATION_LABEL`, default `name`, read from loaded attributes only, else `User #42`), its route on the channel (e-mail address, phone number, …) sent unmasked unless `WATCHTOWER_AGENT_NOTIFICATION_RECIPIENT_ROUTES=false`, the duration from `NotificationSending` and, for failures, the redacted exception (1,000 characters at most). Failures are sent immediately; sent records are buffered. Capped at 100 per execution. Nightwatch's notification record has no recipient and is never marked failed, so Watch Tower needs this to show who received a notification and which ones failed.
- `wt-hello` lists `notifications` when enabled.

### Fixed

- The agent's scheduled tasks no longer stop for up to a day after a run is killed mid-way (for example by a redeploy): their `withoutOverlapping` locks now expire after 5 minutes for the every-minute tasks (`health-report`, `queue-snapshot`, `actions`, `shield-sync`), 15 for `schedule-sync` and 30 for `hello`, instead of Laravel's default 24 hours. A leftover lock would otherwise stop in-app health reports, and Watch Tower would show the checks as Unknown.

## [0.4.2] - 2026-11-11

### Fixed

- With `php artisan route:cache` (usual in production) every browser beacon to `POST /_t/e` answered 500 (`Rate limiter [watchtower-browser] is not defined`): the routes came from the cache, so the provider skipped the code that also defined their rate limiter. The limiter is now defined on every boot.

## [0.4.1] - 2026-11-11

### Fixed

- `watchtower:shield-sync` no longer fails the scheduled task (which Laravel reports as an exception) when Watch Tower is briefly unreachable, for example while it restarts: with cached rules younger than `shield.unreachable_grace_seconds` (`WATCHTOWER_AGENT_SHIELD_UNREACHABLE_GRACE`, 900) it warns and succeeds. Older rules, or none at all, still fail it so a real outage is noticed.

## [0.4.0] - 2026-11-11

### Added

- Browser monitoring (`browser`, on by default, active once `WATCHTOWER_AGENT_BROWSER_KEY` is set): the `@watchtowerBrowser` Blade directive (and `BrowserTag::render()`) writes Watch Tower's browser script tag with the public browser key, release and sample rate.
- A first-party proxy so content blockers do not drop beacons: `GET {path}/s.js` serves Watch Tower's script (cached for an hour) and `POST {path}/e` forwards beacons to `/api/browser/v1/{key}` with `NIGHTWATCH_TOKEN` and the visitor's origin, user agent and IP in `X-Watchtower-*` headers. Outside the `web` group, rate limited per IP (`WATCHTOWER_AGENT_BROWSER_BEACONS_PER_MINUTE`, 120), 404 until configured, never an error for visitors. `WATCHTOWER_AGENT_BROWSER_PROXY=false` loads the script from Watch Tower; `WATCHTOWER_AGENT_BROWSER_PATH` moves the routes (default `/_t`).

## [0.3.1] - 2026-11-10

### Fixed

- `wt-shield` decisions were buffered with the execution, so Nightwatch dropped them for every request its sample rate skipped (with `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1`, nine blocked requests in ten were never reported). Decisions are now sent immediately with `writeNow()`, still carrying the execution header and still capped per execution.
- `wt-auth` `failed` and `lockout` records are sent immediately for the same reason; the other auth events stay buffered.

### Added

- Throttle for immediate security records: at most one `wt-shield` record per (action, rule, IP) and one `failed`/`lockout` per (event, IP, identifier) every 10 seconds, shared across processes through the cache (`shield.throttle_seconds` / `WATCHTOWER_AGENT_SHIELD_THROTTLE_SECONDS`, `auth.throttle_seconds` / `WATCHTOWER_AGENT_AUTH_THROTTLE_SECONDS`, `0` = off). Records carry no count, so Watch Tower's hit counters count windows during an attack.
- A sent Shield decision forces Nightwatch to keep the request it was made for (`Core::sample(1.0)` through `Transport::sample()`), so the blocked request itself is visible; `shield.sample_decisions` / `WATCHTOWER_AGENT_SHIELD_SAMPLE_DECISIONS=false` turns it off.

## [0.3.0] - 2026-11-10

### Added

- `wt-auth` (`auth`, on by default): `Login`, `Failed`, `Lockout`, `Logout`, `PasswordReset`, `Registered`, `Verified` and `OtherDeviceLogout` as buffered in-execution records with guard, user id, client IP (trusted proxies respected) and user agent. Failed logins and lockouts carry the attempted identifier, read only from `auth.identifier_keys` (default `email`, `username`, `login`, `phone`; keys matching the redaction pattern are ignored, so the password is never read) and masked per `auth.identifier_mode` (`masked` by default, `full` or `none`). Capped at 100 per execution.
- `wt-hello` lists `auth` when enabled.
- Shield (`shield`, off by default): bot and spam protection from rules managed in Watch Tower. A global middleware placed after `TrustProxies` evaluates the allow list, blocked IPs (IPv4/IPv6 CIDR), user agent expressions, path globs, rate limits (per `ip`, `user` or `ip_path`, with `block_seconds` penalties), the honeypot and Turnstile challenges, answering 403 or 429 with `Retry-After` (JSON when asked) without naming the rule. Monitor mode only reports `would_block`; `WATCHTOWER_AGENT_SHIELD_MODE` overrides the mode.
- Shield rules are pulled from `/api/shield/v1/rules` with the environment token (2 s timeout, `If-None-Match`/304) by `watchtower:shield-sync` every minute, cached for their ttl with the last good copy kept, and refreshed after the response on a miss. Without rules every request is allowed.
- Turnstile challenge: an interstitial for browser GETs, `POST /_watchtower/shield/verify` checking the token with `TURNSTILE_SECRET` from the app's env, and a signed `wt_shield_pass` cookie (30 minutes, bound to the client IP).
- `@watchtowerHoneypot` Blade directive and `WatchtowerAgent::honeypotField()`.
- `wt-shield` in-execution records for every decision that is not "allow"; `wt-hello` lists `shield` when enabled.

## [0.2.0] - 2026-11-10

### Added

- In-execution records: buffered with the current Nightwatch execution and carrying its `trace_id`, `execution_source`, `execution_id`, `execution_stage` and `user` (read by `Transport::execution()`), capped per execution. A recursion guard keeps the agent from recording itself.
- `wt-event`, `wt-event-summary`, `wt-listener-run` (`events`): application events with redacted payloads and per-listener timing through `InstrumentedDispatcher`, a dispatcher subclass that shares all state with the original (dispatch-only fallback when that isn't possible), default deny filters, allow/deny patterns, sampling, a per-execution cap with a summary, and queued listener runs linked by event id through hidden Context.
- `wt-broadcast` (`broadcasts`): queued, sent (accepted by the driver) and failed stages from decorated broadcast connections and the `BroadcastEvent` job, sharing one broadcast id. Delivery is never claimed.
- `wt-cache-op` (`cache_ops`): flushes, lock flushes and failover (Laravel 12/13 events, guarded), a `flush` macro so `Cache::flush()` is observable, and opt-in lock acquire/block/release. Key masking.
- `wt-mail` (`mail`): queued, sending, sent (with the transport Message-ID) and failed stages linked by an `X-Watchtower-Mail-Id` header; masked recipients, optional Bcc and preview.
- `wt-http` (`http`): every outgoing attempt from Laravel's Http client and from Guzzle via `WatchtowerAgent::guzzleMiddleware()`, with retry numbering, timeout detection (Guzzle 7 and 8), sanitised headers and optional capped bodies.
- Trace context (`trace_context`): W3C `traceparent` on outgoing requests and `wt-trace-link` for incoming ones.
- Webhooks (`webhooks`): `watchtower.webhook:{source}` middleware producing `wt-webhook-in` with delivery ids and duplicate detection, `WatchtowerAgent::webhookSignature()`, the `Http::withWatchtowerWebhook()` macro and spatie/laravel-webhook-server events as tagged `wt-http`.
- `wt-hello` lists the new features.

### Changed

- The remote action client's own requests to Watch Tower are excluded from `wt-http` and carry no `traceparent`.

## [0.1.0] - 2026-11-10

### Added

- `Transport`, the only class that talks to Nightwatch's Core: sends records with `writeNow()` or buffers them, and does nothing when Nightwatch is missing, disabled or paused. It never throws.
- `wt-hello` (`watchtower:hello`, hourly): package, Laravel, PHP and schedule-catchup versions plus the enabled features.
- `wt-schedule` (`watchtower:schedule-sync`, every 10 minutes): the full schedule, with group hashes that match Nightwatch's.
- `wt-queue-snapshot` (`watchtower:queue-snapshot`, every minute): real queue depth per connection and queue, and the failed job count.
- `wt-worker`: worker heartbeats on start, every 30 seconds while looping, and on stop.
- `wt-output`: the tail of scheduled task output files and of `Artisan::call()` output, with optional `capture_all_task_output`.
- `wt-catchup` and `wt-outage` for cyberwizard/schedule-catchup, from its 1.2 occurrence events or, on 1.1, from its tables.
- `wt-health` (`watchtower:health-report`, every minute): database, Redis, cache, disk, queue and failed job checks, plus `WatchtowerAgent::check()` for application checks.
- Remote actions (`watchtower:actions`, off by default): HMAC-verified, expiring, replay-protected `retry_failed_job`, reported back over HTTP and as `wt-action-result`.

### Not included

- Query bindings. Nightwatch 1.x offers no reliable key to correlate a query record with its bindings; the `bindings` flag is reserved and never reported as enabled.

[0.3.1]: https://github.com/cyberwizard-dev/watchtower-agent/releases/tag/v0.3.1
[0.3.0]: https://github.com/cyberwizard-dev/watchtower-agent/releases/tag/v0.3.0
[0.2.0]: https://github.com/cyberwizard-dev/watchtower-agent/releases/tag/v0.2.0
[0.1.0]: https://github.com/cyberwizard-dev/watchtower-agent/releases/tag/v0.1.0
