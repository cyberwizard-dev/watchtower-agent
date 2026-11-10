<?php

namespace Cyberwizard\WatchtowerAgent\Tests\Support;

use Laravel\Nightwatch\Contracts\Ingest;
use RuntimeException;

class FakeIngest implements Ingest
{
    /** @var list<array<mixed>> */
    public array $written = [];

    /** @var list<array<mixed>> */
    public array $sentNow = [];

    public bool $failWriteNow = false;

    public function write(array $record): void
    {
        $this->written[] = $record;
    }

    public function writeNow(array $record): void
    {
        if ($this->failWriteNow) {
            throw new RuntimeException('Agent unreachable');
        }

        $this->sentNow[] = $record;
    }

    public function ping(): void {}

    public function shouldDigest(bool $bool = true): void {}

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void {}

    public function digest(): void {}

    public function flush(): void {}
}
