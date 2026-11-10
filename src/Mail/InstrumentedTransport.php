<?php

namespace Cyberwizard\WatchtowerAgent\Mail;

use Stringable;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Wraps a mailer's Symfony transport only to see send failures: Laravel dispatches no event
 * when the transport throws. The exception is recorded and rethrown unchanged.
 */
class InstrumentedTransport implements Stringable, TransportInterface
{
    public function __construct(
        private TransportInterface $inner,
        private MailRecorder $recorder,
    ) {}

    public function inner(): TransportInterface
    {
        return $this->inner;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->inner->send($message, $envelope);
        } catch (Throwable $exception) {
            $this->recorder->transportFailed($message, $exception);

            throw $exception;
        }
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->inner->{$method}(...$parameters);

        return $result === $this->inner ? $this : $result;
    }
}
