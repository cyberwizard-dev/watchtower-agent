<?php

namespace Cyberwizard\WatchtowerAgent\Metrics;

use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;

/**
 * wt-metric: a custom gauge sent by the application (orders processed, queue lag,
 * account balance, ...) so Watch Tower can chart and alert on it.
 *
 * Metrics are sent out of band with send(), not buffered, so Nightwatch's sample rate can never
 * drop a business number. Invalid names and non-finite values are ignored rather than thrown:
 * a typo in a metric call must not break the request that reports it.
 */
class MetricRecorder
{
    /** Same shape the Watch Tower normalizer accepts. */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}$/';

    public const MAX_TAGS = 20;

    public const MAX_TAG_KEY = 40;

    public const MAX_TAG_VALUE = 100;

    public function __construct(private readonly Recorder $recorder) {}

    /**
     * @param  array<string, string|int|float|bool|null>  $tags
     */
    public function record(string $name, int|float|string $value, array $tags = []): bool
    {
        if (! WatchtowerAgent::feature('metrics') || Guard::active()) {
            return false;
        }

        $name = trim($name);

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return false;
        }

        $value = $this->number($value);

        if ($value === null) {
            return false;
        }

        return Guard::safely(fn (): bool => $this->recorder->send('wt-metric', [
            'name' => $name,
            'value' => $value,
            'tags' => $this->tags($tags) ?: null,
        ]), false);
    }

    private function number(int|float|string $value): ?float
    {
        if (is_string($value)) {
            if (! is_numeric(trim($value))) {
                return null;
            }

            $value = (float) trim($value);
        }

        $value = (float) $value;

        return is_finite($value) ? $value : null;
    }

    /**
     * Flat scalar tags only, keys and values within the normalizer's limits.
     *
     * @param  array<string, string|int|float|bool|null>  $tags
     * @return array<string, string>
     */
    private function tags(array $tags): array
    {
        $clean = [];

        foreach ($tags as $key => $value) {
            if (count($clean) >= self::MAX_TAGS) {
                break;
            }

            if (! is_string($key) || preg_match('/^[A-Za-z0-9_.:-]{1,'.self::MAX_TAG_KEY.'}$/', $key) !== 1) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                continue;
            }

            $clean[$key] = mb_substr((string) $value, 0, self::MAX_TAG_VALUE);
        }

        return $clean;
    }
}
