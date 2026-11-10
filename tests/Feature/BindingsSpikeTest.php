<?php

/*
 * Phase 7 spike: can a Nightwatch query record be tied to the bindings of the QueryExecuted
 * event that produced it? These tests pin down why the answer is no, so the "bindings" feature
 * stays unavailable until Nightwatch exposes a key.
 */

use Illuminate\Support\Facades\DB;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Records\Query;

it('gives redactQueries callbacks a record without bindings or the source event', function () {
    $received = [];
    app(Core::class)->redactQueries(function (Query $query) use (&$received): void {
        $received[] = $query;
    });

    DB::select('select ? as value', [42]);

    expect($received)->toHaveCount(1)
        ->and(array_keys(get_object_vars($received[0])))->toBe(['sql', 'file', 'line', 'duration', 'connection', 'connectionType']);
});

it('writes identical keys for repeated queries in one trace', function () {
    DB::select('select ? as value', [1]);
    DB::select('select ? as value', [2]);

    // Records hold LazyValue objects until they are encoded, as on the wire.
    $queries = collect($this->ingest->written)->where('t', 'query')
        ->map(fn (array $record) => json_decode(json_encode($record), true))->values();

    expect($queries)->toHaveCount(2)
        ->and($queries[0]['_group'])->toBe($queries[1]['_group'])
        ->and($queries[0]['trace_id'])->toBe($queries[1]['trace_id'])
        ->and($queries[0]['execution_id'])->toBe($queries[1]['execution_id']);
});

it('hashes the group from the sql after redaction', function () {
    app(Core::class)->redactQueries(function (Query $query): void {
        $query->sql = 'redacted';
    });

    DB::select('select 1');

    $query = collect($this->ingest->written)->firstWhere('t', 'query');

    expect($query['_group'])->toBe(hash('xxh128', 'testing,redacted'));
});
