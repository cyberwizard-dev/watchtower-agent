<?php

namespace Cyberwizard\WatchtowerAgent\Broadcasting;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Throwable;

/**
 * Decorates one broadcast connection: times broadcast() and records whether the driver
 * accepted the publish call. Everything else is forwarded untouched. "Accepted" is all a
 * driver tells us; delivery to subscribers is never claimed.
 */
class InstrumentedBroadcaster implements Broadcaster
{
    public function __construct(
        private Broadcaster $inner,
        private BroadcastRecorder $recorder,
        public readonly string $connection,
        public readonly string $driver,
    ) {}

    public function inner(): Broadcaster
    {
        return $this->inner;
    }

    public function auth($request)
    {
        return $this->inner->auth($request);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $this->inner->validAuthenticationResponse($request, $result);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $started = hrtime(true);

        try {
            $result = $this->inner->broadcast($channels, $event, $payload);
        } catch (Throwable $exception) {
            $this->recorder->attempted($this, $channels, (string) $event, $payload, $this->elapsed($started), $exception);

            throw $exception;
        }

        $this->recorder->attempted($this, $channels, (string) $event, $payload, $this->elapsed($started));

        return $result;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->inner->{$method}(...$parameters);

        return $result === $this->inner ? $this : $result;
    }

    private function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1000);
    }
}
