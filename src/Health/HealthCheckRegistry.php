<?php

namespace Cyberwizard\WatchtowerAgent\Health;

use Closure;

class HealthCheckRegistry
{
    /**
     * @var array<string, Closure>
     */
    private array $checks = [];

    public function register(string $name, Closure $check): void
    {
        $this->checks[$name] = $check;
    }

    /**
     * @return array<string, Closure>
     */
    public function all(): array
    {
        return $this->checks;
    }

    public function forget(string $name): void
    {
        unset($this->checks[$name]);
    }
}
