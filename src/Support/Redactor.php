<?php

namespace Cyberwizard\WatchtowerAgent\Support;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionObject;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * Turns application values into small, redacted, JSON-safe payloads: models become
 * {class, key}, other objects their class (plus public properties for plain value objects),
 * keys that look sensitive are masked, and the encoded JSON is capped.
 */
class Redactor
{
    public const MASK = '[redacted]';

    public const DEFAULT_KEY_PATTERN = '/pass(word|wd|phrase)?|secret|token|api[_-]?key|private[_-]?key|credential|authorization|cookie|session|credit[_-]?card|card[_-]?number|cvv|cvc|ssn|iban/i';

    private const MAX_DEPTH = 4;

    private const MAX_ITEMS = 100;

    private const MAX_STRING = 2000;

    public static function keyPattern(): string
    {
        $pattern = config('watchtower-agent.redaction.key_pattern');

        return is_string($pattern) && $pattern !== '' && @preg_match($pattern, '') !== false
            ? $pattern
            : self::DEFAULT_KEY_PATTERN;
    }

    public static function sensitiveKey(string|int $key): bool
    {
        return is_string($key) && preg_match(self::keyPattern(), $key) === 1;
    }

    /**
     * The public properties of an event object, or the payload of a string event.
     *
     * @return array<array-key, mixed>
     */
    public static function eventPayload(mixed $event, array $payload = []): array
    {
        if (! is_object($event)) {
            return self::redact(self::normalize(array_values($payload)));
        }

        $data = [];

        foreach ((new ReflectionObject($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($event)) {
                continue;
            }

            $data[$property->getName()] = self::normalize($property->getValue($event), 1);
        }

        return self::redact($data);
    }

    /**
     * A JSON-safe copy of the value. Models and model collections become references, other
     * objects their class name, so nothing is lazy-loaded or serialised by accident.
     */
    public static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value;
        }

        if ($value instanceof Model) {
            return ['class' => $value::class, 'key' => self::scalarKey($value->getKey())];
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($depth >= self::MAX_DEPTH) {
            return is_object($value) ? ['class' => $value::class] : '[…]';
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            $normalized = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if (++$count > self::MAX_ITEMS) {
                    $normalized['…'] = (count($value) - self::MAX_ITEMS).' more';

                    break;
                }

                $normalized[$key] = self::normalize($item, $depth + 1);
            }

            return $normalized;
        }

        if ($value instanceof Closure) {
            return ['class' => Closure::class];
        }

        if (is_object($value)) {
            // Plain value objects (stdClass, DTOs) keep their public data; anything with
            // behaviour is identified by class only.
            if ($value instanceof \stdClass) {
                return self::normalize(get_object_vars($value), $depth + 1);
            }

            return ['class' => $value::class];
        }

        return null;
    }

    /**
     * Mask every value whose key matches the redaction key pattern, at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (self::sensitiveKey($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    /**
     * Mask sensitive "key=value" and JSON "key": "value" pairs inside free text.
     */
    public static function text(string $text): string
    {
        $full = self::keyPattern();
        $pattern = substr($full, 1, (int) strrpos($full, $full[0]) - 1);

        return (string) (preg_replace(
            [
                '/("(?:[^"]*(?:'.$pattern.')[^"]*)"\s*:\s*)"[^"]*"/i',
                '/\b([\w.-]*(?:'.$pattern.')[\w.-]*\s*[=:]\s*)[^\s&,;"]+/i',
            ],
            ['$1"'.self::MASK.'"', '$1'.self::MASK],
            $text,
        ) ?? $text);
    }

    /**
     * JSON for the value, cut to $maxBytes. Returns [json, truncated].
     *
     * @return array{0: string|null, 1: bool}
     */
    public static function json(mixed $value, int $maxBytes = 8192): array
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable) {
            return [null, false];
        }

        if (! is_string($json)) {
            return [null, false];
        }

        return self::cap($json, $maxBytes);
    }

    /**
     * Cut a string to $maxBytes without splitting a UTF-8 character. Returns [text, truncated].
     *
     * @return array{0: string, 1: bool}
     */
    public static function cap(string $text, int $maxBytes): array
    {
        $maxBytes = max(0, $maxBytes);

        if (strlen($text) <= $maxBytes) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, $maxBytes, 'UTF-8'), true];
    }

    /**
     * "Class: message", at most 500 characters, with sensitive pairs masked.
     */
    public static function exception(Throwable $exception): string
    {
        $text = $exception::class.': '.self::text($exception->getMessage());

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 499).'…' : $text;
    }

    /**
     * Mask an e-mail address as "j***@example.com".
     */
    public static function email(string $address): string
    {
        $at = strrpos($address, '@');

        if ($at === false) {
            return $address === '' ? '' : mb_substr($address, 0, 1).'***';
        }

        return mb_substr($address, 0, 1).'***'.substr($address, $at);
    }

    private static function scalarKey(mixed $key): string|int|null
    {
        if (is_int($key) || is_string($key) || $key === null) {
            return $key;
        }

        return is_scalar($key) || $key instanceof \Stringable ? (string) $key : null;
    }
}
