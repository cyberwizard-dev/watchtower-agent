<?php

namespace Cyberwizard\WatchtowerAgent\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The Watch Tower record contract (v1), field for field. Specs: "string", "int", "float",
 * "bool", "list", "array", "object" (a non-empty string-keyed array), with a leading "?" for
 * nullable.
 */
class Contract
{
    public const HEADER = [
        'v' => 'int',
        't' => 'string',
        'timestamp' => 'float',
        'deploy' => 'string',
        'server' => 'string',
        'agent_version' => 'string',
    ];

    /**
     * The execution header in-execution records copy from Nightwatch (QuerySensor.php:60-72).
     */
    public const EXECUTION = [
        'trace_id' => 'string',
        'execution_source' => 'string',
        'execution_id' => 'string',
        'execution_stage' => 'string',
        'user' => 'string',
    ];

    public const IN_EXECUTION = [
        'wt-event', 'wt-event-summary', 'wt-listener-run', 'wt-broadcast', 'wt-cache-op',
        'wt-mail', 'wt-http', 'wt-webhook-in', 'wt-trace-link', 'wt-auth', 'wt-shield',
    ];

    public const TYPES = [
        'wt-hello' => [
            'laravel_version' => 'string',
            'php_version' => 'string',
            'features' => 'list',
            'schedule_catchup_version' => '?string',
        ],
        'wt-schedule' => [
            'tasks' => 'list',
        ],
        'wt-queue-snapshot' => [
            'queues' => 'list',
            'failed_jobs' => '?int',
        ],
        'wt-worker' => [
            'worker_id' => 'string',
            'host' => 'string',
            'pid' => 'int',
            'connection' => 'string',
            'queue' => 'string',
            'status' => 'string',
            'started_at' => 'float',
            'jobs_processed' => '?int',
            'memory' => '?int',
            'reason' => '?string',
        ],
        'wt-output' => [
            'kind' => 'string',
            'group' => 'string',
            'trace_id' => '?string',
            'name' => 'string',
            'exit_code' => '?int',
            'output' => 'string',
            'truncated' => 'bool',
        ],
        'wt-catchup' => [
            'group' => 'string',
            'name' => 'string',
            'scheduled_for' => 'float',
            'ran_at' => 'float',
            'outcome' => 'string',
            'exit_code' => '?int',
            'attempts' => 'int',
            'error' => '?string',
            'duration' => '?int',
            'output' => '?string',
            'truncated' => 'bool',
        ],
        'wt-outage' => [
            'started_at' => 'float',
            'ended_at' => 'float',
            'minutes' => 'int',
        ],
        'wt-health' => [
            'checks' => 'list',
        ],
        'wt-action-result' => [
            'action_id' => 'string',
            'status' => 'string',
            'message' => 'string',
        ],
        'wt-event' => [
            'event_id' => 'string',
            'group' => 'string',
            'name' => 'string',
            'class' => '?string',
            'broadcast' => 'bool',
            'queued_listeners' => 'int',
            'listeners' => 'list',
            'payload' => '?string',
            'truncated' => 'bool',
            'duration' => 'int',
        ],
        'wt-event-summary' => [
            'dropped' => 'int',
            'names' => 'list',
        ],
        'wt-listener-run' => [
            'event_id' => '?string',
            'event_name' => 'string',
            'listener' => 'string',
            'job_id' => '?string',
            'attempt' => 'int',
            'status' => 'string',
            'duration' => 'int',
            'exception' => '?string',
        ],
        'wt-broadcast' => [
            'broadcast_id' => 'string',
            'event_id' => '?string',
            'name' => 'string',
            'class' => 'string',
            'connection' => 'string',
            'driver' => 'string',
            'channels' => 'list',
            'queued' => 'bool',
            'job_id' => '?string',
            'stage' => 'string',
            'duration' => '?int',
            'payload' => '?string',
            'truncated' => 'bool',
            'exception' => '?string',
            'socket_excluded' => 'bool',
        ],
        'wt-cache-op' => [
            'op' => 'string',
            'store' => 'string',
            'driver' => 'string',
            'key' => '?string',
            'duration' => '?int',
            'ttl' => '?int',
            'error' => '?string',
        ],
        'wt-mail' => [
            'mail_id' => 'string',
            'stage' => 'string',
            'mailable' => '?string',
            'notification' => '?string',
            'mailer' => 'string',
            'transport' => 'string',
            'from' => '?string',
            'to' => 'list',
            'cc' => 'list',
            'bcc' => 'list',
            'subject' => '?string',
            'message_id' => '?string',
            'preview' => '?string',
            'queued' => 'bool',
            'job_id' => '?string',
            'duration' => '?int',
            'error' => '?string',
        ],
        'wt-http' => [
            'http_id' => 'string',
            'group' => 'string',
            'method' => 'string',
            'url' => 'string',
            'host' => 'string',
            'path' => 'string',
            'attempt' => 'int',
            'status_code' => '?int',
            'outcome' => 'string',
            'duration' => '?int',
            'connect_timeout' => '?float',
            'timeout' => '?float',
            'request_headers' => '?object',
            'response_headers' => '?object',
            'request_body' => '?string',
            'response_body' => '?string',
            'traceparent' => '?string',
            'webhook' => '?object',
        ],
        'wt-webhook-in' => [
            'webhook_id' => 'string',
            'source' => 'string',
            'endpoint' => 'string',
            'method' => 'string',
            'signature' => 'string',
            'status' => 'string',
            'http_status' => 'int',
            'duration' => 'int',
            'delivery_id' => '?string',
            'duplicate' => 'bool',
            'payload' => '?string',
            'exception' => '?string',
        ],
        'wt-trace-link' => [
            'parent_trace_id' => 'string',
            'parent_span_id' => 'string',
        ],
        'wt-auth' => [
            'event' => 'string',
            'guard' => '?string',
            'user_id' => '?string',
            'identifier' => '?string',
            'ip' => '?string',
            'user_agent' => '?string',
            'remember' => '?bool',
        ],
        'wt-shield' => [
            'action' => 'string',
            'rule' => 'string',
            'ip' => 'string',
            'method' => 'string',
            'path' => 'string',
            'user_agent' => '?string',
            'user_id' => '?string',
            'rules_version' => 'string',
        ],
    ];

    public const EVENT_LISTENER = [
        'listener' => 'string',
        'queued' => 'bool',
        'duration' => '?int',
        'status' => 'string',
        'exception' => '?string',
    ];

    public const EVENT_SUMMARY_NAME = [
        'name' => 'string',
        'count' => 'int',
    ];

    public const BROADCAST_CHANNEL = [
        'name' => 'string',
        'type' => 'string',
    ];

    public const HTTP_WEBHOOK = [
        'event' => 'string',
        'destination' => 'string',
    ];

    public const SCHEDULE_TASK = [
        'group' => 'string',
        'name' => 'string',
        'cron' => 'string',
        'timezone' => 'string',
        'repeat_seconds' => 'int',
        'without_overlapping' => 'bool',
        'on_one_server' => 'bool',
        'run_in_background' => 'bool',
        'even_in_maintenance_mode' => 'bool',
        'description' => '?string',
        'replay_on_catchup' => 'bool',
        'next_run_at' => '?float',
    ];

    public const QUEUE = [
        'connection' => 'string',
        'queue' => 'string',
        'size' => '?int',
        'pending' => '?int',
        'delayed' => '?int',
        'reserved' => '?int',
        'oldest_pending_at' => '?float',
    ];

    public const HEALTH_CHECK = [
        'name' => 'string',
        'status' => 'string',
        'value' => '?float',
        'unit' => '?string',
        'message' => 'string',
        'duration_ms' => '?int',
    ];

    public const ENUMS = [
        'wt-worker.status' => ['starting', 'alive', 'stopping'],
        'wt-output.kind' => ['scheduled-task', 'command'],
        'wt-catchup.outcome' => ['replayed', 'failed', 'deferred', 'exhausted'],
        'wt-action-result.status' => ['done', 'failed', 'rejected'],
        'wt-health.status' => ['healthy', 'degraded', 'unhealthy'],
        'wt-listener-run.status' => ['ok', 'failed', 'released'],
        'wt-broadcast.stage' => ['queued', 'sent', 'failed'],
        'wt-cache-op.op' => ['flush', 'flush-failure', 'locks-flush', 'lock-acquired', 'lock-blocked', 'lock-released', 'failover'],
        'wt-mail.stage' => ['queued', 'sending', 'sent', 'failed'],
        'wt-http.outcome' => ['response', 'connection-failed', 'timeout'],
        'wt-webhook-in.signature' => ['valid', 'invalid', 'missing', 'unchecked'],
        'wt-webhook-in.status' => ['processed', 'failed', 'rejected'],
        'wt-event.listeners.status' => ['ok', 'failed', 'queued'],
        'wt-broadcast.channels.type' => ['public', 'private', 'presence'],
        'wt-shield.action' => ['blocked', 'rate_limited', 'challenged', 'challenge_passed', 'challenge_failed', 'honeypot', 'would_block'],
        'wt-auth.event' => ['login', 'failed', 'lockout', 'logout', 'password_reset', 'registered', 'verified', 'other_device_logout'],
    ];

    /**
     * @param  array<string, mixed>  $record
     */
    public static function assertRecord(array $record, string $type): void
    {
        Assert::assertSame($type, $record['t'] ?? null);
        Assert::assertSame(1, $record['v']);

        $execution = in_array($type, self::IN_EXECUTION, true) ? self::EXECUTION : [];

        self::assertShape($record, self::HEADER + $execution + self::TYPES[$type], $type);

        json_encode($record, JSON_THROW_ON_ERROR);

        foreach (self::ENUMS as $path => $allowed) {
            [$enumType, $field] = explode('.', $path, 2);

            if ($enumType === $type && ! str_contains($field, '.') && array_key_exists($field, $record)) {
                Assert::assertContains($record[$field], $allowed, "{$path} has an unknown value");
            }
        }

        match ($type) {
            'wt-schedule' => array_map(fn (array $task) => self::assertShape($task, self::SCHEDULE_TASK, 'wt-schedule.tasks[]'), $record['tasks']),
            'wt-queue-snapshot' => array_map(fn (array $queue) => self::assertShape($queue, self::QUEUE, 'wt-queue-snapshot.queues[]'), $record['queues']),
            'wt-health' => array_map(function (array $check): void {
                self::assertShape($check, self::HEALTH_CHECK, 'wt-health.checks[]');
                Assert::assertContains($check['status'], self::ENUMS['wt-health.status']);
            }, $record['checks']),
            'wt-event' => array_map(function (array $listener): void {
                self::assertShape($listener, self::EVENT_LISTENER, 'wt-event.listeners[]');
                Assert::assertContains($listener['status'], self::ENUMS['wt-event.listeners.status']);
                Assert::assertSame($listener['queued'], $listener['duration'] === null, 'listener duration is null exactly when queued');
            }, $record['listeners']),
            'wt-event-summary' => array_map(fn (array $name) => self::assertShape($name, self::EVENT_SUMMARY_NAME, 'wt-event-summary.names[]'), $record['names']),
            'wt-broadcast' => array_map(function (array $channel): void {
                self::assertShape($channel, self::BROADCAST_CHANNEL, 'wt-broadcast.channels[]');
                Assert::assertContains($channel['type'], self::ENUMS['wt-broadcast.channels.type']);
            }, $record['channels']),
            'wt-http' => $record['webhook'] === null ? null : self::assertShape($record['webhook'], self::HTTP_WEBHOOK, 'wt-http.webhook'),
            'wt-mail' => array_map(fn ($address) => Assert::assertIsString($address), [...$record['to'], ...$record['cc'], ...$record['bcc']]),
            'wt-shield' => $record['user_agent'] === null ? null : Assert::assertLessThanOrEqual(255, mb_strlen($record['user_agent']), 'wt-shield.user_agent is at most 255 characters'),
            'wt-auth' => $record['user_agent'] === null ? null : Assert::assertLessThanOrEqual(255, mb_strlen($record['user_agent']), 'wt-auth.user_agent is at most 255 characters'),
            default => null,
        };

        if (in_array($type, ['wt-event', 'wt-http'], true)) {
            Assert::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $record['group']);
        }

        foreach (['event_id', 'broadcast_id', 'mail_id', 'http_id', 'webhook_id'] as $uuidField) {
            if (in_array($type, self::IN_EXECUTION, true) && isset($record[$uuidField])) {
                Assert::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $record[$uuidField], "{$type}.{$uuidField} must be a uuid");
            }
        }

        if (in_array($type, ['wt-output', 'wt-catchup'], true) || $type === 'wt-schedule') {
            foreach ($type === 'wt-schedule' ? array_column($record['tasks'], 'group') : [$record['group']] as $group) {
                Assert::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $group);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $spec
     */
    public static function assertShape(array $data, array $spec, string $label): void
    {
        $missing = array_diff(array_keys($spec), array_keys($data));
        $extra = array_diff(array_keys($data), array_keys($spec));

        Assert::assertSame([], array_values($missing), "{$label} is missing fields");
        Assert::assertSame([], array_values($extra), "{$label} has fields outside the contract");

        foreach ($spec as $field => $kind) {
            $value = $data[$field];
            $nullable = str_starts_with($kind, '?');
            $kind = ltrim($kind, '?');

            if ($value === null) {
                Assert::assertTrue($nullable, "{$label}.{$field} must not be null");

                continue;
            }

            $ok = match ($kind) {
                'string' => is_string($value),
                'int' => is_int($value),
                'float' => is_float($value),
                'bool' => is_bool($value),
                'list' => is_array($value) && array_is_list($value),
                'array' => is_array($value),
                // Encodes as a JSON object: non-empty with string keys.
                'object' => is_array($value) && $value !== [] && ! array_is_list($value),
            };

            Assert::assertTrue($ok, "{$label}.{$field} must be {$kind}, got ".get_debug_type($value));
        }
    }
}
