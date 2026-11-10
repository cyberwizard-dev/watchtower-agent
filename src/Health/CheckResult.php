<?php

namespace Cyberwizard\WatchtowerAgent\Health;

final class CheckResult
{
    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    public const UNHEALTHY = 'unhealthy';

    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?float $value = null,
        public readonly ?string $unit = null,
    ) {}

    public static function healthy(string $message = 'OK', int|float|null $value = null, ?string $unit = null): self
    {
        return new self(self::HEALTHY, $message, $value === null ? null : (float) $value, $unit);
    }

    public static function degraded(string $message, int|float|null $value = null, ?string $unit = null): self
    {
        return new self(self::DEGRADED, $message, $value === null ? null : (float) $value, $unit);
    }

    public static function unhealthy(string $message, int|float|null $value = null, ?string $unit = null): self
    {
        return new self(self::UNHEALTHY, $message, $value === null ? null : (float) $value, $unit);
    }

    /**
     * @return array{name: string, status: string, value: ?float, unit: ?string, message: string, duration_ms: ?int}
     */
    public function toRecord(string $name, ?int $durationMs): array
    {
        return [
            'name' => $name,
            'status' => $this->status,
            'value' => $this->value,
            'unit' => $this->unit,
            'message' => $this->message,
            'duration_ms' => $durationMs,
        ];
    }
}
