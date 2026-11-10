<?php

use Cyberwizard\WatchtowerAgent\Support\OutputTail;
use Cyberwizard\WatchtowerAgent\Support\TaskGroup;
use Cyberwizard\WatchtowerAgent\Tests\Support\Contract;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

beforeEach(function () {
    $this->transport = $this->fakeTransport();
    $this->file = tempnam(sys_get_temp_dir(), 'wt-output-');
});

afterEach(function () {
    @unlink($this->file);
});

it('sends the output file of a finished task', function () {
    file_put_contents($this->file, "Processed 3 invoices\n\e[32mDone\e[0m\n");
    $task = app(Schedule::class)->command('inspire')->hourly()->sendOutputTo($this->file);
    $task->exitCode = 0;
    Context::addHidden('nightwatch_trace_id', 'trace-123');

    event(new ScheduledTaskFinished($task, 0.2));

    $record = $this->transport->ofType('wt-output')[0];
    Contract::assertRecord($record, 'wt-output');

    expect($record)->toMatchArray([
        'kind' => 'scheduled-task',
        'group' => TaskGroup::for($task),
        'trace_id' => 'trace-123',
        'name' => TaskGroup::name($task),
        'exit_code' => 0,
        'output' => "Processed 3 invoices\nDone\n",
        'truncated' => false,
    ]);
});

it('reports a failed console task once, on the failed event', function () {
    file_put_contents($this->file, 'boom');
    $task = app(Schedule::class)->command('inspire')->hourly()->sendOutputTo($this->file);
    $task->exitCode = 1;

    event(new ScheduledTaskFinished($task, 0.2));
    event(new ScheduledTaskFailed($task, new RuntimeException('failed'), 0.2));

    $records = $this->transport->ofType('wt-output');

    expect($records)->toHaveCount(1)->and($records[0]['exit_code'])->toBe(1);
});

it('skips tasks whose output goes to the null device', function () {
    $task = app(Schedule::class)->command('inspire')->hourly();
    $task->exitCode = 0;

    event(new ScheduledTaskFinished($task, 0.2));

    expect($task->output)->toBe($task->getDefaultOutput())
        ->and($this->transport->ofType('wt-output'))->toBe([]);
});

it('keeps only the tail of long output', function () {
    config(['watchtower-agent.max_output_bytes' => 10]);
    file_put_contents($this->file, str_repeat('a', 50).'0123456789');
    $task = app(Schedule::class)->command('inspire')->hourly()->sendOutputTo($this->file);
    $task->exitCode = 0;

    event(new ScheduledTaskFinished($task, 0.2));

    expect($this->transport->ofType('wt-output')[0])->toMatchArray([
        'output' => '0123456789',
        'truncated' => true,
    ]);
});

it('drops a multibyte character cut by the tail and scrubs invalid UTF-8', function () {
    $tail = OutputTail::fromString('xx'."\u{00E9}".'abc', 4);

    expect($tail->output)->toBe('abc')->and($tail->truncated)->toBeTrue();

    $scrubbed = OutputTail::fromString("ok \xFF\xFE done", 100);
    expect(mb_check_encoding($scrubbed->output, 'UTF-8'))->toBeTrue()
        ->and(json_encode($scrubbed->output))->not->toBeFalse();
});

it('reads files from the end without loading the whole file', function () {
    file_put_contents($this->file, str_repeat('x', 200_000).'END');

    $tail = OutputTail::fromFile($this->file, 3);

    expect($tail->output)->toBe('END')->and($tail->truncated)->toBeTrue();
});

it('stores the output of every command task when capture_all_task_output is on', function () {
    config(['watchtower-agent.capture_all_task_output' => true]);
    $task = app(Schedule::class)->command('inspire')->hourly();

    event(new ScheduledTaskStarting($task));

    expect($task->output)->not->toBe($task->getDefaultOutput())
        ->and($task->output)->toContain('logs'.DIRECTORY_SEPARATOR.'schedule-');
});

it('leaves task output alone by default', function () {
    $task = app(Schedule::class)->command('inspire')->hourly();

    event(new ScheduledTaskStarting($task));

    expect($task->output)->toBe($task->getDefaultOutput());
});

it('captures Artisan::call output without consuming it', function () {
    Artisan::command('wt:say', function () {
        $this->line('hello from the command');

        return 3;
    });

    $exitCode = Artisan::call('wt:say');

    expect($exitCode)->toBe(3)
        ->and(Artisan::output())->toBe("hello from the command\n");

    $record = collect($this->transport->ofType('wt-output'))->firstWhere('name', 'wt:say');
    Contract::assertRecord($record, 'wt-output');

    expect($record)->toMatchArray([
        'kind' => 'command',
        'group' => hash('xxh128', 'wt:say'),
        'exit_code' => 3,
        'output' => "hello from the command\n",
        'truncated' => false,
    ]);
});

it('does not capture terminal output', function () {
    $terminal = new StreamOutput(fopen('php://memory', 'w+'));
    $terminal->write('to the terminal');

    event(new CommandFinished('wt:terminal', new ArrayInput([]), $terminal, 0));

    expect($this->transport->ofType('wt-output'))->toBe([]);
});
