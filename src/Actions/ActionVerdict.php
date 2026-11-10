<?php

namespace Cyberwizard\WatchtowerAgent\Actions;

final class ActionVerdict
{
    private function __construct(
        public readonly bool $accepted,
        public readonly string $reason,
        public readonly bool $report,
    ) {}

    public static function accept(): self
    {
        return new self(true, '', true);
    }

    /**
     * @param  bool  $report  whether the rejection is posted back to Watch Tower (a replayed id
     *                        is not, so it can't overwrite the original result)
     */
    public static function reject(string $reason, bool $report = true): self
    {
        return new self(false, $reason, $report);
    }
}
