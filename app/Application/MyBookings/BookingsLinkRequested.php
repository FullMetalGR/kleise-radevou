<?php

declare(strict_types=1);

namespace App\Application\MyBookings;

/**
 * A My bookings link was minted for an address with bookings here, and the customer is to be
 * mailed it. Announced after the row is written, outside any transaction, as a transition is.
 *
 * It carries the link's id, which the job re-reads the address by, and the plaintext token, the
 * one moment it exists: the row holds the hash. It is no transition record and is not marked as
 * one, since it moves no booking; its listener hands the send to a queued job as every listener
 * does, and that job travels encrypted because the token rides in it.
 *
 * And the language the message is written in, the code of the request's language, en or el: the
 * message names no booking, so no bookings.language binds it, and no row records the language the
 * form was asked in, so it rides here to the job, which writes the words in it and mints the link
 * under its prefix.
 */
final readonly class BookingsLinkRequested
{
    /**
     * @param  string  $language  the code of the language the request that asked for the link was answered in
     */
    public function __construct(
        public int $linkId,
        public string $token,
        public string $language,
    ) {}
}
