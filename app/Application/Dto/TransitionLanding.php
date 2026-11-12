<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Booking\TransitionColumn;
use App\Domain\Enum\BookingStatus;
use BackedEnum;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Where one attempt of a guarded transition lands: the status the compare-and-set writes and
 * what else the row is set to beside it.
 *
 * It is an answer and not a description. A transition says how its landing is found, a function
 * of the re-read and of the clock and, for an approval, of the tenant's payment connection as
 * its row stands, and the shared implementation asks it under the lock, after the key set is
 * confirmed and the guard has allowed, in the attempt that writes. So a status that depends on
 * the row is read from the row, and an instant written beside it is the attempt's own, never
 * one carried over from an attempt that rolled back. Beside what the caller says, a reject's
 * reason or the approver's id, one thing in it may be known before the transaction opened: an
 * approval's payment hold in hours, read from the settings row first as a hold reads its own
 * TTL. The expiry it makes is no instant here but a ServerNowPlus, which the compare-and-set
 * writes as the server's own now() plus those hours.
 *
 * The columns are checked here, where they are stated, against the short list a transition may
 * write. A landing out of a held state names hold_class and hold_expires_at, null where the
 * booking is held no longer; a landing from a state with no hold has none to end and may name
 * nothing.
 *
 * Not the Landing beside it, which is where the insert protocol leaves a chain it has just
 * written. That one is chosen by the caller before the write; this one is found under the lock.
 */
final readonly class TransitionLanding
{
    /** @var array<string, scalar|DateTimeImmutable|ServerNowPlus|null> keyed by TransitionColumn values */
    public array $columns;

    /**
     * @param  BookingStatus  $status  the status the compare-and-set writes
     * @param  array<array-key, mixed>  $columns  what else the row is set to, keyed by TransitionColumn values, each value a scalar, a backed enum, an instant, an expiry from the server's now or null
     *
     * @throws InvalidArgumentException for a column outside TransitionColumn, or a value that is not reducible to a binding
     */
    public function __construct(
        public BookingStatus $status,
        array $columns = [],
    ) {
        $written = [];

        foreach ($columns as $column => $value) {
            if (! is_string($column) || TransitionColumn::tryFrom($column) === null) {
                throw new InvalidArgumentException(sprintf(
                    'The column "%s" is outside the list a transition may write: %s.',
                    (string) $column,
                    implode(', ', array_map(fn (TransitionColumn $known): string => $known->value, TransitionColumn::cases())),
                ));
            }

            $written[$column] = self::binding($column, $value);
        }

        $this->columns = $written;
    }

    /**
     * A value in the vocabulary a transition thinks in, reduced to what a column holds: an enum
     * becomes its backing value, a scalar and a null are already one, and an instant stays an
     * instant. It stays one because how an instant crosses to the store is the store's to
     * spell, in the one place that spells it, and a landing that formatted it here would be
     * the second. An expiry from the server's now stays one for the same reason.
     *
     * @return scalar|DateTimeImmutable|ServerNowPlus|null
     */
    private static function binding(string $column, mixed $value): string|int|float|bool|DateTimeImmutable|ServerNowPlus|null
    {
        return match (true) {
            $value === null, is_scalar($value), $value instanceof DateTimeImmutable, $value instanceof ServerNowPlus => $value,
            $value instanceof BackedEnum => $value->value,
            default => throw new InvalidArgumentException(sprintf(
                'The value written to "%s" is a %s; a column takes a scalar, a backed enum, an instant, a ServerNowPlus or null.',
                $column,
                get_debug_type($value),
            )),
        };
    }
}
