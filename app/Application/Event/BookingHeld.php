<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;

/**
 * A hold was taken and committed: the booking exists and its items block.
 *
 * The hold class is on the record because the acknowledgement rule is decided by it and by
 * nothing else, and a listener reading the row back could find a class that has already
 * changed. Everything else a listener needs it re-reads from the booking.
 *
 * It carries one thing a re-read cannot supply: the plaintext manage token. That token is
 * delivered in the hold response and in the customer's email link, the app never shows it, and
 * only the hash is stored, so the email is the customer's only durable copy of their magic link
 * and a listener re-reading the row finds nothing it could put in one. The plaintext exists in
 * exactly two moments and this is the first: inside HoldBooking, where it is minted. It is
 * optional because a record built anywhere else has no token to carry and must not pretend to.
 *
 * Where it goes from here is the reason the field is safe to add. The listener that carries it
 * runs synchronously, on the thread that published this record and while the tenant is still
 * known, and all it does is dispatch a job whose payload is encrypted. So the plaintext sits in
 * no row and no log in the clear, and is gone once the message is sent.
 *
 * The five facts are spelled out rather than taken from CarriesTheTransition, because a trait
 * that six records share cannot grow a seventh field for two of them. The record implements the
 * marker on its own, like every other, so a listener subscribes to this class or to
 * TransitionEvent and both work; there is no parent here either, because the dispatcher never
 * walks one.
 */
final readonly class BookingHeld implements TransitionEvent
{
    public function __construct(
        public string $uuid,
        public ?BookingStatus $from,
        public BookingStatus $to,
        public ?HoldClass $holdClass,
        public Actor $actor,
        public ?string $manageToken = null,
    ) {}
}
