<?php

declare(strict_types=1);

namespace App\Application\Notification;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A set of recipients with each mailbox in it once.
 *
 * One address is mailed once however many rules named it, which is the whole reason this is a
 * type rather than an array: the recipient rule unions three limbs that overlap by design, and
 * a manager who is also the member on the booking must not receive the same message twice.
 *
 * The first naming of a mailbox is the one kept. The limbs are ordered so that the ones
 * carrying a user come first, and an address that can act is never replaced by the same address
 * with no identity behind it.
 *
 * @implements IteratorAggregate<int, Recipient>
 */
final readonly class Recipients implements Countable, IteratorAggregate
{
    /** @var list<Recipient> */
    public array $all;

    private function __construct(Recipient ...$recipients)
    {
        $kept = [];

        foreach ($recipients as $recipient) {
            $kept[$recipient->mailbox()] ??= $recipient;
        }

        $this->all = array_values($kept);
    }

    public static function of(Recipient ...$recipients): self
    {
        return new self(...$recipients);
    }

    public function count(): int
    {
        return count($this->all);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all);
    }
}
