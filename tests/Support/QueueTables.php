<?php

namespace Cyberwizard\WatchtowerAgent\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class QueueTables
{
    public static function create(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        config([
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => 'testing',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'queue.failed' => [
                'driver' => 'database-uuids',
                'database' => 'testing',
                'table' => 'failed_jobs',
            ],
        ]);

        app()->forgetInstance('queue.failer');
    }
}
