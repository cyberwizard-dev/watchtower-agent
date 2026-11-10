<?php

namespace Cyberwizard\WatchtowerAgent\Contracts;

interface RecordTransport
{
    /**
     * Send a record to the Nightwatch agent immediately. Returns false when the record was
     * not handed over (Nightwatch missing, disabled or paused, or the write failed).
     *
     * @param  array<string, mixed>  $record
     */
    public function send(array $record): bool;

    /**
     * Add a record to the current execution's buffer. It is digested with the request,
     * command or job and follows Nightwatch's sampling.
     *
     * @param  array<string, mixed>  $record
     */
    public function buffer(array $record): void;

    /**
     * The execution header of the request, command, job or scheduled task Nightwatch is
     * currently capturing, or null when Nightwatch is missing, disabled or paused.
     *
     * @return array{trace_id: string, execution_source: string, execution_id: string, execution_stage: string, user: string}|null
     */
    public function execution(): ?array;

    /**
     * Make Nightwatch keep the execution it is currently capturing, whatever its sample rate
     * decided, so the buffered records (the request itself, its queries, ...) are digested
     * instead of dropped. Does nothing when Nightwatch is missing, disabled or paused.
     */
    public function sample(): void;
}
