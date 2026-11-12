<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\ServerNowPlus;
use App\Domain\Booking\TransitionColumn;
use DateTimeImmutable;

/**
 * The columns a statement writes beside a booking's status, as the text that assigns them and
 * the bindings that fill it.
 *
 * Three statements write them and none may spell this itself: the compare-and-set of a guarded
 * transition, one row at a time under a lock, and the sweeper's two grants, onto confirmed and
 * onto awaiting_payment, a whole batch at once with no guard. The grants write the four columns
 * of an approval, the compare-and-set those or any others of the short list. Every instant given
 * is cast, because an instant crosses to the server as text; a second copy of the loop is a copy
 * that can forget a cast, and the column whose cast it forgets is the one the server would then
 * read in the session's time zone. An expiry from the server's now is no instant given: its
 * column is written as now() plus the hours it binds, with no cast, by the compare-and-set and
 * the grant onto awaiting_payment, and the grant onto confirmed is never handed one.
 *
 * The caller decides only what to set. The order is the caller's own, so the bindings and the
 * assignments come back in the order the column map was written in.
 */
final readonly class WrittenColumns
{
    /**
     * @param  list<TransitionColumn>  $columns  in the order given
     * @param  list<string|int|float|bool|null>  $bindings  one per column, in the same order
     * @param  list<TransitionColumn>  $fromServerNow  the columns among them written as the server's now() plus a binding of hours
     */
    private function __construct(
        public array $columns,
        public array $bindings,
        public array $fromServerNow,
    ) {}

    /**
     * @param  array<string, scalar|DateTimeImmutable|ServerNowPlus|null>  $columns  keyed by TransitionColumn values
     */
    public static function of(array $columns): self
    {
        $bindings = [];
        $fromServerNow = [];

        foreach ($columns as $column => $value) {
            if ($value instanceof ServerNowPlus) {
                $fromServerNow[] = TransitionColumn::from($column);
            }

            $bindings[] = match (true) {
                $value instanceof DateTimeImmutable => SqlText::instant($value),
                $value instanceof ServerNowPlus => $value->hours,
                default => $value,
            };
        }

        return new self(array_map(TransitionColumn::from(...), array_keys($columns)), $bindings, $fromServerNow);
    }

    /**
     * The SET fragment, leading comma included, for a statement that has already set the status:
     * one assignment per column, in the order given, each with the placeholder its type needs.
     *
     * An instant written from the server's now is spelled as that sum in the statement, the way
     * every hold's expiry is written where the hold is created, and binds its hours alone.
     *
     * @param  list<TransitionColumn>  $columns
     * @param  list<TransitionColumn>  $fromServerNow  the columns among them written as the server's now() plus a binding of hours
     */
    public static function assignments(array $columns, array $fromServerNow = []): string
    {
        $set = '';

        foreach ($columns as $column) {
            $set .= sprintf(', %s = %s', $column->value, in_array($column, $fromServerNow, true) ? 'now() + make_interval(hours => ?)' : self::placeholder($column));
        }

        return $set;
    }

    /**
     * How a column takes its value: an instant is cast, because it crosses as text, and the rest
     * bind as they are.
     */
    private static function placeholder(TransitionColumn $column): string
    {
        return match ($column) {
            TransitionColumn::HoldClass,
            TransitionColumn::ApprovedBy,
            TransitionColumn::RejectionReason,
            TransitionColumn::PaymentRef => '?',
            TransitionColumn::HoldExpiresAt,
            TransitionColumn::ApprovedAt => '?::timestamptz',
        };
    }
}
