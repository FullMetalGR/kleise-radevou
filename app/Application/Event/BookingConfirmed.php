<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;

/**
 * A booking was confirmed and the commit has happened: the slot is the customer's and the
 * booking is no longer on a hold.
 *
 * The hold class it carries is the one it was settled from, so the acknowledgement rule can
 * tell the customer who was waiting on an approval from the customer who was still in the
 * booking flow, which the row itself can no longer say: confirming nulls the class. Everything
 * else a listener needs it re-reads from the booking.
 *
 * Everything but one. The plaintext manage token is the second of the two moments it exists: a
 * confirm request the guard has just verified is holding it, and only its hash is stored, so a
 * listener that wants to put the magic link in the confirmation has no way to read one back. It
 * is optional because a confirmation reached any other way - the admin's - has no token in hand
 * and must not pretend to; that message then carries the booking without a link, which is the
 * honest answer rather than a broken one.
 *
 * The third moment is the payment's: the pay hop saw the token and sealed it onto the row, and
 * the paid transition opens it here. afterPayment says the booking was confirmed by its
 * payment, or settled at the pay hop where nothing could take the money: either way not by the
 * customer's own confirm, which is what tells the send to ask the reader for the calendar
 * file's number and, with no token, to say where the customer's link is. The name is older than
 * the hop's settlement and says more than the flag does: it is true for an on-site settlement
 * that moved no money, so it never says that money moved.
 *
 * The five facts are spelled out rather than taken from CarriesTheTransition, because a trait
 * six records share cannot grow a seventh field for two of them. The record implements the
 * marker on its own, like every other, and has no parent, because the dispatcher never walks
 * one.
 */
final readonly class BookingConfirmed implements TransitionEvent
{
    public function __construct(
        public string $uuid,
        public ?BookingStatus $from,
        public BookingStatus $to,
        public ?HoldClass $holdClass,
        public Actor $actor,
        public ?string $manageToken = null,
        public bool $afterPayment = false,
    ) {}
}
