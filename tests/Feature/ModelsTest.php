<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Ticket;
use Cyberwizard\WatchtowerAgent\Models\ModelRecorder;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->transport = $this->fakeTransport();

    Schema::create('customers', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('customer_id')->nullable();
        $table->string('number');
        $table->timestamps();
    });

    Schema::create('tickets', function (Blueprint $table): void {
        $table->id();
        $table->string('subject');
        $table->timestamps();
        $table->softDeletes();
    });

    $this->customer = Customer::create(['name' => 'Ada']);
    $this->order = Order::create(['customer_id' => $this->customer->id, 'number' => 'ORD-1']);

    app(ModelRecorder::class)->reset();
});

function models(object $test): array
{
    return array_slice($test->transport->bufferedOfType('wt-model'), -1);
}

function flushModels(): array
{
    app(ModelRecorder::class)->flush();

    return models(test());
}

it('records lifecycle events and query counts per model as one record per execution', function () {
    $this->order->update(['number' => 'ORD-2']);
    $this->order->delete();

    $record = flushModels()[0];

    Contract::assertRecord($record, 'wt-model');

    $order = collect($record['models'])->firstWhere('model', Order::class);

    expect($order['ops'])->toMatchArray(['updating' => 1, 'updated' => 1, 'deleting' => 1, 'deleted' => 1])
        ->and($order['operations'])->toBeGreaterThanOrEqual(4)
        ->and($order['queries'])->toBeGreaterThanOrEqual(2)
        ->and($order['duration'])->toBeInt()
        ->and($record['events'])->not->toBeEmpty();
});

it('attributes eager and lazy relationship loads to the parent model', function () {
    Customer::with('orders')->get();

    $record = flushModels()[0];
    $customer = collect($record['models'])->firstWhere('model', Customer::class);
    $eager = collect($customer['relations'])->firstWhere('name', 'orders');

    expect($eager)->not->toBeNull()
        ->and($eager['related'])->toBe(Order::class)
        ->and($eager['eager'])->toBe(1)
        ->and($eager['lazy'])->toBe(0);

    app(ModelRecorder::class)->reset();

    Customer::first()->orders;

    $record = flushModels()[0];
    $customer = collect($record['models'])->firstWhere('model', Customer::class);
    $lazy = collect($customer['relations'])->firstWhere('name', 'orders');

    expect($lazy['lazy'])->toBe(1)
        ->and($lazy['eager'])->toBe(0);
});

it('keeps exact counts while bounding event samples', function () {
    config(['watchtower-agent.models.max_events' => 2, 'watchtower-agent.models.max_query_groups' => 1]);

    Order::create(['number' => 'ORD-3']);
    Order::create(['number' => 'ORD-4']);
    Order::create(['number' => 'ORD-5']);

    $record = flushModels()[0];
    $order = collect($record['models'])->firstWhere('model', Order::class);

    expect($order['ops']['created'])->toBe(3)
        ->and($record['events'])->toHaveCount(2)
        ->and($order['query_groups'])->toHaveCount(1);
});

it('links sampled events to the query group that produced them', function () {
    Order::create(['number' => 'ORD-9']);

    $record = flushModels()[0];
    $created = collect($record['events'])->firstWhere('operation', 'created');

    expect($created['group'])->toBeString()->toHaveLength(32);
});

it('masks, hides or keeps model keys', function () {
    Order::first();
    expect(collect(flushModels()[0]['events'])->firstWhere('operation', 'retrieved')['key'])->toBe('*');

    config(['watchtower-agent.models.keys' => 'hidden']);
    app(ModelRecorder::class)->reset();
    Order::first();
    expect(collect(flushModels()[0]['events'])->firstWhere('operation', 'retrieved')['key'])->toBeNull();

    config(['watchtower-agent.models.keys' => 'full']);
    app(ModelRecorder::class)->reset();
    Order::first();
    expect(collect(flushModels()[0]['events'])->firstWhere('operation', 'retrieved')['key'])->toBe('1');
});

it('ignores raw query-builder queries and excluded models', function () {
    DB::table('orders')->get();

    expect(flushModels())->toBe([]);

    config(['watchtower-agent.models.exclude' => [Order::class]]);
    Order::first();

    expect(flushModels())->toBe([]);
});

it('records soft delete and restoration operations', function () {
    $ticket = Ticket::create(['subject' => 'Broken']);

    $ticket->delete();
    $ticket->restore();

    $record = flushModels()[0];
    $ops = collect($record['models'])->firstWhere('model', Ticket::class)['ops'];

    expect($ops)->toMatchArray([
        'deleting' => 1,
        'deleted' => 1,
        'restoring' => 1,
        'restored' => 1,
    ]);
});

it('records bulk updates as queries without lifecycle events', function () {
    Order::query()->update(['number' => 'BULK']);

    $record = flushModels()[0];
    $order = collect($record['models'])->firstWhere('model', Order::class);

    expect($order)->not->toBeNull()
        ->and($order['queries'])->toBe(1)
        ->and($order['operations'])->toBe(0);
});

it('carries the execution header of a job or command', function () {
    $this->transport->execution = [
        'trace_id' => 'trace-worker',
        'execution_source' => 'job',
        'execution_id' => 'attempt-9',
        'execution_stage' => 'action',
        'user' => '',
    ];

    Order::first();

    $record = flushModels()[0];

    expect($record)->toMatchArray([
        'trace_id' => 'trace-worker',
        'execution_source' => 'job',
        'execution_id' => 'attempt-9',
    ]);
});

it('never captures model attributes', function () {
    config(['watchtower-agent.models.keys' => 'full']);

    Order::create(['number' => 'SECRET-NUMBER']);

    expect(json_encode(flushModels()[0]))->not->toContain('SECRET-NUMBER');
});

it('does nothing when the feature is off', function () {
    config(['watchtower-agent.features.models' => false]);

    Order::first();

    expect(flushModels())->toBe([]);
});

it('starts a new execution when the id changes', function () {
    Order::first();
    flushModels();

    $this->transport->newExecution('00000000-0000-4000-8000-000000000002');
    Order::first();

    $record = flushModels()[0];

    expect($record['execution_id'])->toBe('00000000-0000-4000-8000-000000000002')
        ->and(collect($record['models'])->firstWhere('model', Order::class)['ops']['retrieved'])->toBe(1);
});
