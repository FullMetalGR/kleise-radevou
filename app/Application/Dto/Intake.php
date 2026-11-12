<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Enum\BookingChannel;
use App\Domain\Enum\BookingSource;
use InvalidArgumentException;

/**
 * What a booking carries from the surface it came through: the source the server stamped, the
 * channel a hosted-page link was tagged with where it was one of the fixed list, the customer's
 * note where they wrote one, and the language the booking is made in: the hold request's
 * language, or the tenant default for the admin's manual booking, written once as
 * bookings.language and never updated. None of it plans anything: the planner carries it to the
 * write untouched, which stores the source, the channel and the language on the booking row and
 * the note as booking_meta's key note in the same transaction.
 *
 * The language is its code, en or el, the value of the resolver's answer (App\Tenancy\Languages):
 * this layer names no class of the HTTP or the site's, and the column's CHECK holds the code. It
 * is en where a caller names none, the column's own default.
 *
 * The source has no default, as its column has none, so every caller names it. A channel and a
 * note are absent far more often than present, and absent is a real answer for both.
 *
 * The note is the customer's own words and personal data by default: it reaches the business's
 * dialog and the business's two messages through its own reader, and the audit trail, the booking
 * payload and every customer message never.
 */
final readonly class Intake
{
    /** The longest note a customer may leave, in characters. */
    public const int NOTE_MAX = 500;

    /** booking_meta's key for the customer's note. */
    public const string NOTE_KEY = 'note';

    public function __construct(
        public BookingSource $source,
        public ?BookingChannel $channel = null,
        public ?string $note = null,
        public string $language = 'en',
    ) {
        if ($note !== null && trim($note) === '') {
            throw new InvalidArgumentException('A note says something; an empty one is no note.');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidArgumentException(sprintf('A note is at most %d characters, not %d.', self::NOTE_MAX, mb_strlen($note)));
        }
    }
}
