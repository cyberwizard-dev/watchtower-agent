<?php

use App\OctaneScannerFixtures\UnsafeSingleton;
use Cyberwizard\WatchtowerAgent\Octane\SingletonSafetyScanner;

it('finds mutable request-specific state on registered application singletons', function () {
    $directory = app_path('OctaneScannerFixtures');
    $file = $directory.'/UnsafeSingleton.php';
    mkdir($directory, 0777, true);
    file_put_contents($file, <<<'PHP'
<?php

namespace App\OctaneScannerFixtures;

class UnsafeSingleton
{
    public ?string $user = null;

    public function remember(): void
    {
        $this->user = auth()->user()?->getAuthIdentifier();
    }
}
PHP);

    require_once $file;
    app()->singleton(UnsafeSingleton::class);

    try {
        $result = app(SingletonSafetyScanner::class)->scan();
        $finding = collect($result['findings'])->firstWhere('class', UnsafeSingleton::class);

        expect($result['classes_scanned'])->toBeGreaterThan(0)
            ->and($finding)->not->toBeNull()
            ->and($finding['property'])->toBe('user')
            ->and($finding['severity'])->toBe('high')
            ->and($finding['line'])->toBe(7)
            ->and($finding['file'])->toContain('OctaneScannerFixtures/UnsafeSingleton.php');
    } finally {
        @unlink($file);
        @rmdir($directory);
    }
});

it('sends singleton scan findings to Watch Tower', function () {
    $transport = $this->fakeTransport();

    $this->artisan('watchtower:octane-scan')->assertSuccessful();

    expect($transport->ofType('wt-octane-scan'))->toHaveCount(1)
        ->and($transport->ofType('wt-octane-scan')[0]['scanner_version'])->toBe('1.0.0');
});
