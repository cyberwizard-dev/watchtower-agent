<?php

use App\Events\OrderCancelled;
use App\Events\PodcastPublishedNow;
use App\Mail\OrderShippedMail;
use Cyberwizard\WatchtowerAgent\Contracts\RecordTransport;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

it('never lets a failing transport break the host app', function () {
    $this->app->instance(RecordTransport::class, new class implements RecordTransport
    {
        public function send(array $record): bool
        {
            throw new RuntimeException('send broke');
        }

        public function buffer(array $record): void
        {
            throw new RuntimeException('buffer broke');
        }

        public function execution(): ?array
        {
            return ['trace_id' => 't', 'execution_source' => 'request', 'execution_id' => 'e', 'execution_stage' => 'action', 'user' => ''];
        }
    });
    $this->app->forgetInstance(Recorder::class);

    View::addLocation(__DIR__.'/../Fixtures/views');
    config([
        'mail.default' => 'array',
        'mail.mailers.array' => ['transport' => 'array'],
        'mail.from' => ['address' => 'shop@example.com', 'name' => 'Shop'],
        'broadcasting.default' => 'log',
        'broadcasting.connections.log' => ['driver' => 'log'],
    ]);
    Http::fake();
    Event::listen(OrderCancelled::class, fn () => 'handled');

    expect(event(new OrderCancelled))->toBe(['handled']);

    event(new PodcastPublishedNow);
    Http::get('https://api.example.com');
    Mail::to('jane@example.com')->send(new OrderShippedMail);
    Cache::clear();

    expect(Http::recorded())->toHaveCount(1);
});

it('keeps Nightwatch Core access inside Transport', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'Transport.php') {
            $code = file_get_contents($file->getPathname());

            if (str_contains($code, 'ingest->') || str_contains($code, 'executionState') || str_contains($code, 'Laravel\\Nightwatch\\Core')) {
                $offenders[] = $file->getFilename();
            }
        }
    }

    expect($offenders)->toBe([]);
});
