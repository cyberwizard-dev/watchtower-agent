# Changelog

Notable changes to `cyberwizard/watchtower-agent` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
