<?php

namespace Cyberwizard\WatchtowerAgent\Events;

/**
 * One captured dispatch while its listeners run.
 */
final class EventFrame
{
    /** @var list<array{listener: string, queued: bool, duration: int|null, status: string, exception: string|null}> */
    public array $listeners = [];

    public int $queuedListeners = 0;

    public int $omittedListeners = 0;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $class,
        public readonly bool $broadcast,
        public readonly ?string $payload,
        public readonly bool $truncated,
        public readonly int $startedAt,
        public readonly mixed $previousContext,
    ) {}
}
