<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The two spellings every statement here binds and nothing infers: an instant, and a list of
 * ids. Spelled in one place so a reader and a writer cannot drift apart.
 *
 * An instant crosses to the server as UTC text with its offset written out, never as a bound
 * date object: the framework would format the object without its zone, and the server would
 * then read it in the session's time zone, which is whatever the cluster was installed with. A
 * list of ids crosses as a bigint array literal, because the pgsql driver binds every parameter
 * untyped and the statement casts it where the server cannot infer.
 *
 * The third spelling is the one thing that is written into a statement rather than bound: an
 * enum case, where the server cannot infer the type of a parameter inside an IN list or a
 * CASE. It takes an enum and never a string, so nothing a caller was handed can reach the text.
 *
 * The fourth is not a value at all but a predicate, whether a hold is still live, which is here
 * for the same reason the others are: two statements ask it and two answers would be a page
 * offering a button the sweeper has already voided. It is written into the text as well, from a
 * literal column name and nothing a caller was handed.
 *
 * The rest are the spellings the readers shared and now read from here: an instant the server
 * wrote out read back, a uuid's pattern checked before one is bound, and a booking's first
 * start, the subquery every screen that orders or bounds a booking by its start joins it to;
 * beside it a booking's last segment end, which the retention lines count from.
 */
final readonly class SqlText
{
    /**
     * The one spelling of an instant on the wire, both ways: what instant() writes, and what
     * instantColumn() has the server write back.
     */
    public const string INSTANT = 'Y-m-d H:i:s.uP';

    /**
     * A uuid as the bookings and the sessions spell one, in either case, anchored at both ends so
     * a value carrying anything after it, a trailing newline included, is no uuid.
     */
    public const string UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    private const string TO_CHAR = 'YYYY-MM-DD HH24:MI:SS.US"+00:00"';

    public static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT);
    }

    /**
     * An instant as the server wrote it out, text carrying its offset, read back in UTC; the
     * queued jobs read the instant they carry as text, the trail's spelling, through it as well
     * (SendMove, SendMoveNotice, SendReminder). The parsing zone is named as well as the reading
     * one: the offset in the text decides today, and a rendering that ever stopped carrying one
     * would otherwise fall back to the process default rather than to anything this code chose.
     */
    public static function readInstant(string $text): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return (new DateTimeImmutable($text, $utc))->setTimezone($utc);
    }

    /**
     * Whether a value is a uuid, asked before one is bound: a string that is no uuid names no
     * row, and is answered so by its reader rather than reaching the server as a malformed
     * literal, which the server refuses outright.
     */
    public static function isUuid(string $value): bool
    {
        return preg_match(self::UUID, $value) === 1;
    }

    /**
     * A timestamptz expression as the server hands it back: UTC text with its offset written
     * out, whatever zone the session runs in, in the same spelling instant() sends.
     */
    public static function instantColumn(string $expression): string
    {
        return 'to_char('.$expression." at time zone 'UTC', '".self::TO_CHAR."')";
    }

    /**
     * Whether a hold is still live, as an expression the server evaluates: the expiry against
     * now() inside the statement, false where the column holds nothing at all.
     *
     * It is spelled here because two statements ask it and they may never disagree. The re-read
     * under a booking's own row lock carries it as a column, and the decision pages ask it of a
     * booking they are about to offer a button for. Both are the same two limbs of the blocking
     * predicate, and the reason neither compares an instant of its own is that hold_expires_at
     * is written by now() and the sweep selects against now(): a caller comparing the row
     * against the application's clock would offer a button the sweeper has already voided, or
     * hide one that is still live, by whatever the two clocks differ by.
     *
     * The third limb of the predicate, an auto-approving request that blocks past its expiry,
     * is not here: it is a question about the slot the request is sitting on, and the reap and
     * the availability read spell it where it belongs.
     *
     * @param  literal-string  $column  the expiry column, qualified as the statement qualifies
     *                                  it, and written in the statement rather than bound,
     *                                  which is why nothing a caller was handed may reach it
     * @return literal-string
     */
    public static function holdIsLive(string $column): string
    {
        return '('.$column.' is not null and '.$column.' > now())';
    }

    /**
     * A booking's first start, the earliest of its items' starts: a statement over the schema's
     * booking_items grouped by booking, answering booking_id and starts_at, which a reader joins
     * as first on first.booking_id = booking.id. It is a booking's start wherever a screen orders
     * or bounds bookings by one: the Bookings list, the Customers screen's visits and the
     * reports' range. Unparenthesised, as a joinSub takes it.
     */
    public static function firstStarts(string $schema): string
    {
        return 'select booking_id, min(start_utc) as starts_at from '.$schema.'.booking_items group by booking_id';
    }

    /**
     * A booking's last segment end, the latest of its items' ends: a statement over the schema's
     * booking_items grouped by booking, answering booking_id and ends_at, which a statement
     * joins as ended on ended.booking_id = booking.id. It is the end the customer was sold, the
     * end_utc column, and never a block's end past a buffer or a processing gap's, as the first
     * start is the start_utc column: the instant the retention lines of the 24 months and the 30
     * days count from. Unparenthesised, as firstStarts is.
     */
    public static function lastEnds(string $schema): string
    {
        return 'select booking_id, max(end_utc) as ends_at from '.$schema.'.booking_items group by booking_id';
    }

    /**
     * The schema's bookings, aliased booking, each joined to its first start, aliased first: the
     * from clause of a statement that reads bookings by their start.
     */
    public static function bookingsByFirstStart(string $schema): string
    {
        return $schema.'.bookings booking join ('.self::firstStarts($schema).') first on first.booking_id = booking.id';
    }

    /**
     * @param  list<int>  $ids
     */
    public static function bigintArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }

    /**
     * An enum case as a quoted literal in a statement's text.
     *
     * The quote is doubled, which is how a single quote is escaped in an SQL string and is the
     * whole of the escaping a standard-conforming string needs. It is done although every case
     * this takes today is a bare lowercase word, because the argument that made it safe was
     * about the values rather than about the type: BackedEnum admits any string, so a case
     * spelled with a quote in it would otherwise reach statement text as itself. The type says
     * what may be written here and this says what happens to it, rather than the two of them
     * together happening to be safe.
     */
    public static function literal(BackedEnum $case): string
    {
        return "'".str_replace("'", "''", (string) $case->value)."'";
    }
}
