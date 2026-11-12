<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A transport that fails every send the way a mail host having a bad afternoon does, with the
 * recipient in its message, which is exactly what must not reach a log.
 *
 * It keeps every message it refused, in the order they arrived, so a test can read what the
 * seam handed over beside the one line the seam logged about it: the two are written in the
 * same order, one pair per message, because the seam logs each refusal as it happens.
 */
final class RefusingTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $refused = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->refused[] = $message;

        throw new TransportException('Connection could not be established for recipient perpetua.vandersnoot@example.test');
    }

    public function __toString(): string
    {
        return 'refusing';
    }
}
