<?php

namespace Cyberwizard\WatchtowerAgent\Tests\Support;

use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;

class FakeTransport implements RecordTransport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var list<array<string, mixed>> */
    public array $buffered = [];

    public function send(array $record): bool
    {
        // Same guarantee Nightwatch needs: the record must survive json_encode.
        json_encode($record, JSON_THROW_ON_ERROR);

        $this->sent[] = $record;

        return true;
    }

    /** @var array{trace_id: string, execution_source: string, execution_id: string, execution_stage: string, user: string}|null */
    public ?array $execution = [
        'trace_id' => '00000000-0000-4000-8000-000000000001',
        'execution_source' => 'request',
        'execution_id' => '00000000-0000-4000-8000-000000000001',
        'execution_stage' => 'action',
        'user' => '',
    ];

    public function buffer(array $record): void
    {
        // Buffered records are digested by Nightwatch with json_encode too.
        json_encode($record, JSON_THROW_ON_ERROR);

        $this->buffered[] = $record;
    }

    public function execution(): ?array
    {
        return $this->execution;
    }

    /** How many times the current execution was forced into the sample. */
    public int $sampled = 0;

    public function sample(): void
    {
        $this->sampled++;
    }

    /**
     * Buffered records of one type.
     *
     * @return list<array<string, mixed>>
     */
    public function bufferedOfType(string $type): array
    {
        return array_values(array_filter($this->buffered, fn (array $record): bool => $record['t'] === $type));
    }

    /**
     * Start a new execution so per-execution caps reset.
     */
    public function newExecution(string $id): void
    {
        if ($this->execution !== null) {
            $this->execution['execution_id'] = $id;
            $this->execution['trace_id'] = $id;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->sent, fn (array $record): bool => $record['t'] === $type));
    }
}
