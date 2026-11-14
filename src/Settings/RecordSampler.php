<?php

namespace Cyberwizard\WatchtowerAgent\Settings;

use Cyberwizard\WatchtowerAgent\Transport;
use Throwable;

/**
 * Samples individual records (queries, cache events, outgoing requests, queued jobs, mail and
 * notifications) at the rates set in Watch Tower, through Nightwatch's reject callbacks.
 *
 * Nightwatch samples whole executions; this drops records inside the executions it keeps. A
 * dropped record still counts in its execution's totals (a request's "queries" count), because
 * Nightwatch counts before it asks the callbacks. Callbacks stack, so the app's own reject
 * callbacks keep working. Any failure keeps the record.
 */
class RecordSampler
{
    /** Watch Tower key => Nightwatch reject method. */
    public const HOOKS = [
        'queries' => 'rejectQueries',
        'cache_events' => 'rejectCacheEvents',
        'outgoing_requests' => 'rejectOutgoingRequests',
        'queued_jobs' => 'rejectQueuedJobs',
        'mail' => 'rejectMail',
        'notifications' => 'rejectNotifications',
    ];

    /** @var (callable(): float)|null */
    private static $random = null;

    public function __construct(private SettingsRepository $settings, private Transport $transport) {}

    /**
     * Add the reject callbacks to Nightwatch (once per core, also to a core resolved later).
     */
    public function register(): void
    {
        $callbacks = [];

        foreach (self::HOOKS as $key => $method) {
            $callbacks[$method] = fn (): bool => ! $this->keep($key);
        }

        $this->transport->rejectRecords($callbacks);
    }

    /**
     * Whether to keep one record of this type: always without a rate, never at 0.
     */
    public function keep(string $key): bool
    {
        try {
            $rate = $this->settings->recordRate($key);
        } catch (Throwable) {
            return true;
        }

        if ($rate === null || $rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        $random = self::$random ?? static fn (): float => mt_rand() / mt_getrandmax();

        return $random() < $rate;
    }

    /**
     * Replace the random source in tests (a callable returning 0..1), or restore it with null.
     */
    public static function useRandom(?callable $random): void
    {
        self::$random = $random;
    }
}
