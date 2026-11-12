<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Application\Dto\HoldWindow;
use App\Application\Dto\SoldItem;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Tenancy\Tenant;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Reads one booking of a tenant by its public identifier, for a request that opens no
 * transaction: the container, and its items with the service and the staff member named. Two
 * statements, whatever the chain's length.
 *
 * The hold answers from here rather than from what it just wrote, so the customer is shown the
 * row that exists and not the intention that made it. Every later surface that shows a booking
 * reads through this one too, which is what makes the payload whitelist a decision about a
 * booking rather than a decision about an endpoint.
 *
 * The serial id stays inside: it joins the items to their container and goes no further, since
 * it is kept from everybody. The manage token's hash never joins a snapshot at all: the guard
 * asks for it on its own, so the object a payload is built from cannot be carrying it. Instants
 * cross back as text with an explicit offset, the way they crossed out, so the session's time
 * zone decides nothing.
 *
 * Every table is named by schema and nothing is set on the connection, the way the catalog
 * reader honours the pooling rule.
 */
final readonly class BookingReader
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * The booking that uuid names, or null where no row has it. A string that is not a uuid at
     * all names no row either and is answered the same way, rather than reaching the server as
     * a malformed literal.
     */
    public function snapshot(Tenant $tenant, string $uuid): ?BookingSnapshot
    {
        if (! SqlText::isUuid($uuid)) {
            return null;
        }

        $row = $this->db->connection()
            ->table($tenant->schema_name.'.bookings')
            ->where('uuid', $uuid)
            ->selectRaw('id, status, hold_class, hold_expires_at::text as hold_expires_at, customer_name, customer_email, customer_phone, total_minor, currency, language')
            ->first();

        if ($row === null) {
            return null;
        }

        if (! is_int($row->id) || ! is_string($row->status)
            || ! is_int($row->total_minor) || ! is_string($row->currency) || ! is_string($row->language)
            || ($row->hold_class !== null && ! is_string($row->hold_class))
            || ($row->hold_expires_at !== null && ! is_string($row->hold_expires_at))) {
            throw new LogicException(sprintf('A bookings row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
        }

        $currency = Currency::of($row->currency);

        return new BookingSnapshot(
            $uuid,
            BookingStatus::from($row->status),
            $row->hold_class === null ? null : HoldClass::from($row->hold_class),
            $row->hold_expires_at === null ? null : SqlText::readInstant($row->hold_expires_at),
            // Null where the customer was erased, read as every reader of a snapshot reads it.
            CustomerColumns::read(RowShape::of('bookings', $tenant), $row->customer_name, $row->customer_email, $row->customer_phone),
            new Money($row->total_minor, $currency),
            $this->items($tenant, $row->id, $currency),
            // The language it was made in, which every message to its customer is written in.
            $row->language,
        );
    }

    /**
     * Every booking of this tenant made under an address, as citext compares it, for the My
     * bookings list: the upcoming ones first, by their first start ascending, then the past
     * ones by their first start descending, a booking being upcoming while its first start is
     * not before the instant given, read off the Clock port. Every status is listed, since the
     * page is the customer's account of what they booked here; each is the snapshot the guest
     * view is built from, so the list and the manage page show one booking one way.
     *
     * @return array{upcoming: list<BookingSnapshot>, past: list<BookingSnapshot>}
     */
    public function ofAddress(Tenant $tenant, string $email, DateTimeImmutable $now): array
    {
        $rows = $this->db->connection()->select(
            sprintf(
                'select b.uuid, min(i.start_utc) >= ?::timestamptz as upcoming
                 from %1$s.bookings b
                 join %1$s.booking_items i on i.booking_id = b.id
                 where b.customer_email = ?::citext
                 group by b.id, b.uuid
                 order by min(i.start_utc) >= ?::timestamptz desc,
                     case when min(i.start_utc) >= ?::timestamptz then min(i.start_utc) end asc,
                     min(i.start_utc) desc,
                     b.id',
                $tenant->schema_name,
            ),
            [SqlText::instant($now), $email, SqlText::instant($now), SqlText::instant($now)],
        );

        $listed = ['upcoming' => [], 'past' => []];

        foreach ($rows as $row) {
            if (! is_object($row) || ! isset($row->uuid, $row->upcoming) || ! is_string($row->uuid) || ! is_bool($row->upcoming)) {
                throw new LogicException(sprintf('A bookings row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
            }

            $booking = $this->snapshot($tenant, $row->uuid);

            if ($booking !== null) {
                $listed[$row->upcoming ? 'upcoming' : 'past'][] = $booking;
            }
        }

        return $listed;
    }

    /**
     * The customer as the address's most recent booking at this tenant spelled them, the one
     * made last by bookings.created_at of those that did not come to nothing, or null where the
     * address has none: the prefill of the booking flow's details step under a My bookings
     * session. Made last and not starting last, because what the customer typed most recently is
     * what they would type again; the row's id breaks a tie. A booking cancelled, rejected or
     * expired is read past, BookingStatus::cameToNothing (P9F-L17-d), so a cancelled booking's
     * details never win over the one before it. The address is compared as citext compares it,
     * as the list compares it, and the email answered is the one the booking carries.
     */
    public function latestCustomerOf(Tenant $tenant, string $email): ?Customer
    {
        $readPast = array_values(array_map(
            fn (BookingStatus $status): string => $status->value,
            array_filter(BookingStatus::cases(), fn (BookingStatus $status): bool => $status->cameToNothing()),
        ));

        $row = $this->db->connection()
            ->table($tenant->schema_name.'.bookings')
            ->whereRaw('customer_email = ?::citext', [$email])
            ->whereNotIn('status', $readPast)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->selectRaw('customer_name, customer_email::text as customer_email, customer_phone')
            ->first();

        if ($row === null) {
            return null;
        }

        if (! is_string($row->customer_name) || ! is_string($row->customer_email) || ($row->customer_phone !== null && ! is_string($row->customer_phone))) {
            throw new LogicException(sprintf('A bookings row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
        }

        return new Customer($row->customer_name, $row->customer_email, $row->customer_phone);
    }

    /**
     * The stored hash of a booking's manage token, or null where no booking has that uuid, and
     * null again where retention has nulled the hash, which is what ends the magic link. One
     * statement, and the only place the column is read: the guard compares against it and
     * nothing else ever holds it, because it is kept from every caller.
     *
     * It is read on its own rather than on the snapshot, so that a refusal costs one statement
     * instead of three and so that nothing which can reach a payload ever carries the hash.
     */
    public function manageTokenHash(Tenant $tenant, string $uuid): ?string
    {
        if (! SqlText::isUuid($uuid)) {
            return null;
        }

        $hash = $this->db->connection()
            ->table($tenant->schema_name.'.bookings')
            ->where('uuid', $uuid)
            ->value('manage_token_hash');

        return is_string($hash) ? $hash : null;
    }

    /**
     * Whether this booking's hold is still live, as the server answers it, and false where no
     * booking has that uuid.
     *
     * **Whether there is still a decision to make is the database's answer.** hold_expires_at
     * is written by now(), the sweep selects against now(), and the re-read under the booking's
     * own row lock carries the same predicate as a column, LockedBooking: no clock of ours is
     * in any of those answers. A page that compared the row against the application's clock
     * would offer a button the sweeper has already voided, or hide one that is still live, by
     * whatever the web server and the database differ by. So this asks the question where the
     * others ask it, in the statement, and the spelling is the one SqlText hands both.
     *
     * The expiry a signed link carries is a different question and is not this one: that is a
     * credential's own lifetime, minted equal to this window and compared against the clock
     * that minted it.
     *
     * It is read on its own rather than carried on the snapshot for the reason the policy below
     * is: a snapshot is what a payload is built from and what every surface reads, and this is
     * a question the two decision pages and the two jobs that mint links, SendApprovalRequest
     * and SendApprovalNag, ask about a handful of bookings.
     */
    public function holdIsLive(Tenant $tenant, string $uuid): bool
    {
        if (! SqlText::isUuid($uuid)) {
            return false;
        }

        $live = $this->db->connection()
            ->table($tenant->schema_name.'.bookings')
            ->where('uuid', $uuid)
            ->selectRaw(SqlText::holdIsLive('hold_expires_at').' as hold_is_live')
            ->value('hold_is_live');

        return $live === true;
    }

    /**
     * What will become of an approval request nobody answers, as the booking carried it from
     * the service at hold time: the slot is released, or the request is granted as though the
     * owner had said yes.
     *
     * It is read on its own, the way the token hash is, and for a related reason: it is one
     * column with one reader. The decision page asks for it only where a request's window has
     * closed and the sweeper has not reached it yet, which is a rare page and never the common
     * one, so carrying it on every snapshot would be reading a column for every booking any
     * surface ever shows in order to answer a question about a handful of them.
     *
     * Null where no booking has that uuid, and where the stored word is not one this build
     * knows: a value a later migration adds is a policy this deployment cannot describe, and
     * saying nothing about it is better than naming the wrong one.
     */
    public function approvalTimeout(Tenant $tenant, string $uuid): ?ApprovalTimeout
    {
        if (! SqlText::isUuid($uuid)) {
            return null;
        }

        $policy = $this->db->connection()
            ->table($tenant->schema_name.'.bookings')
            ->where('uuid', $uuid)
            ->value('on_approval_timeout');

        return is_string($policy) ? ApprovalTimeout::tryFrom($policy) : null;
    }

    /**
     * The window an approval request is waiting in, as the insert wrote it: from the row's
     * created_at, which defaults to the same now() the insert set the expiry from, to
     * hold_expires_at. Null where no booking has that uuid, and null where the row is not on an
     * approval hold: settling a request nulls the class, and a row read after that has no window
     * left to measure.
     *
     * It answers for the approval hold alone, because that is the one hold whose start is the
     * row's own stamp and that anything schedules against. A checkout hold's start is the stamp
     * as well, but nothing is timed off a checkout hold; and the payment hold is written at
     * approval, when created_at is hours old, so it needs a start of its own and must not be
     * read from here.
     *
     * It is read on its own rather than on the snapshot for the reason the policy above is: one
     * listener asks it once per hold, and a snapshot is what every surface reads.
     */
    public function approvalWindow(Tenant $tenant, string $uuid): ?HoldWindow
    {
        if (! SqlText::isUuid($uuid)) {
            return null;
        }

        $row = $this->db->connection()
            ->table($tenant->schema_name.'.bookings')
            ->where('uuid', $uuid)
            ->where('hold_class', HoldClass::Approval->value)
            ->whereNotNull('hold_expires_at')
            ->selectRaw('created_at::text as created_at, hold_expires_at::text as hold_expires_at')
            ->first();

        if ($row === null) {
            return null;
        }

        if (! is_string($row->created_at) || ! is_string($row->hold_expires_at)) {
            throw new LogicException(sprintf('A bookings row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
        }

        return new HoldWindow(SqlText::readInstant($row->created_at), SqlText::readInstant($row->hold_expires_at));
    }

    /**
     * Which version of this booking's calendar entry a message may carry: a number that cannot
     * fall over the life of the booking, above the zero the first file carried whenever a move
     * is being announced, and zero for a uuid no booking has.
     *
     * **It is the revision a re-sent calendar file carries**, and the reason it comes from here
     * is what a calendar client does with the number. A client holding an entry updates it on
     * importing a later revision of the same identifier and ignores an earlier one, so the
     * number has to grow with the booking and never with the message: two messages about one
     * booking that each chose a number of their own could reach the customer in either order,
     * and a number that ever went backwards would leave the customer's diary at the old time,
     * which is the one failure the number exists to prevent.
     *
     * **The number is the highest id among the booking's item rows.** A move deletes the items
     * the booking leaves and inserts the ones it takes, RescheduleBooking, under the booking's
     * row lock, so two moves of one booking run one after the other and the second inserts
     * after the first has committed; the ids come from the table's sequence, which hands out
     * each number once and never a lower one after a higher, and a rolled-back attempt's
     * numbers are skipped rather than reused. So every move leaves the maximum strictly higher
     * than it found it. The booking row carries no counter, and the trail is no counter either:
     * retention deletes audit_log rows outright at 24 months, while the booking and its items
     * live 24 months past the last segment, so a booking taken far enough ahead loses its whole
     * trail and can still be moved, and a count of its rescheduled rows would then fall below a
     * number the customer's calendar already holds. The items are the one thing on a booking
     * that retention keeps as long as the booking, and once retention nulls the customer's
     * address no message goes at all.
     *
     * The first file, the confirmation's or the approval's, carries zero by those two
     * templates' choice, and every item id is above it. Nothing here guarantees that the file
     * carrying zero is the first a customer's calendar sees: today it is, because a move of a
     * request carries no file and the confirmation or the approval is the first file for its
     * uuid, and on the payment path, where an approval's file precedes a confirmation's, the
     * confirmation must ask here rather than send a second zero. Gaps cost a client nothing,
     * and the number is never printed to a person. The format's integer is 32-bit, RFC 5545
     * 3.3.8, and the sequence is 64-bit: the two part after two billion draws on the sequence
     * in one tenant's schema, one draw per item per insert, rolled-back attempts included, and
     * nothing here caps the number, because a cap is a number that stops rising.
     *
     * Two moves whose messages both run after both commits read the same maximum and describe
     * the same items, so the two files agree; the stamp decides between them and the later one
     * is no older.
     */
    public function revision(Tenant $tenant, string $uuid): int
    {
        if (! SqlText::isUuid($uuid)) {
            return 0;
        }

        $highest = $this->db->connection()
            ->table($tenant->schema_name.'.booking_items', 'item')
            ->join($tenant->schema_name.'.bookings as booking', 'booking.id', '=', 'item.booking_id')
            ->where('booking.uuid', $uuid)
            ->max('item.id');

        // Null is the maximum over no rows, a uuid no booking has; anything but an integer
        // after that is a shape surprise and is refused, as the reads of this file that build
        // a value refuse one, rather than folded into the absent answer. The three
        // single-column reads above fold a surprise into null or false because their absent
        // answer is harmless; zero here would be read as a booking that is not there, which is
        // the one number this method exists never to return for a booking that is.
        if ($highest === null) {
            return 0;
        }

        if (! is_int($highest)) {
            throw new LogicException(sprintf('A booking_items row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
        }

        return $highest;
    }

    /**
     * The contended range of every item of a booking, in chain order, and nothing else: the
     * first step of a guarded transition, which reads only what it takes to name the staff-days
     * to lock. An unknown booking has no ranges, which the caller reads as no booking; the
     * transition finds out for certain under the lock, where it matters.
     *
     * It is the block range and not the range the customer was sold, because a staff-day mutex
     * row stands for the time the member is occupied, buffers included. It is read here rather
     * than on the snapshot because the snapshot is what a payload is built from, and the range
     * a member is occupied for is the tenant's arrangement and not the customer's business.
     *
     * @return list<StaffBlock>
     */
    public function staffBlocks(Tenant $tenant, string $uuid): array
    {
        if (! SqlText::isUuid($uuid)) {
            return [];
        }

        $rows = $this->db->connection()
            ->table($tenant->schema_name.'.booking_items', 'item')
            ->join($tenant->schema_name.'.bookings as booking', 'booking.id', '=', 'item.booking_id')
            ->where('booking.uuid', $uuid)
            ->orderBy('item.sort')
            ->selectRaw('item.staff_id, item.block_start_utc::text as block_start_utc, item.block_end_utc::text as block_end_utc')
            ->get();

        $blocks = [];

        foreach ($rows as $row) {
            if (! is_int($row->staff_id) || ! is_string($row->block_start_utc) || ! is_string($row->block_end_utc)) {
                throw new LogicException(sprintf('A booking_items row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
            }

            $blocks[] = new StaffBlock($row->staff_id, SqlText::readInstant($row->block_start_utc), SqlText::readInstant($row->block_end_utc));
        }

        return $blocks;
    }

    /**
     * What a booking was sold as, in chain order: each item's service, the member it was sold
     * to, the range that member is occupied for, the price the row fixed at hold time, and where
     * the item starts. The read a move takes before it opens anything, and the read that offers
     * a move takes too, and nothing else about the booking.
     *
     * One statement for both of the things a move needs from it. The contended ranges name the
     * staff-days the booking is leaving, which the union key set is half of; the services, the
     * members and the prices are what the new chain is planned from. Reading them apart would
     * be two statements a moment apart over rows a concurrent move can change between them.
     *
     * It is read here rather than on the snapshot for the reason the block ranges above are: the
     * snapshot is what a payload is built from, and the contended range is kept off every guest
     * surface. The currency is the container's, since the item row holds minor units alone.
     *
     * @return list<SoldItem>
     */
    public function soldItems(Tenant $tenant, string $uuid): array
    {
        if (! SqlText::isUuid($uuid)) {
            return [];
        }

        $rows = $this->db->connection()
            ->table($tenant->schema_name.'.booking_items', 'item')
            ->join($tenant->schema_name.'.bookings as booking', 'booking.id', '=', 'item.booking_id')
            ->where('booking.uuid', $uuid)
            ->orderBy('item.sort')
            ->selectRaw('item.service_id, item.staff_id, item.price_minor, booking.currency,'
                .' item.block_start_utc::text as block_start_utc, item.block_end_utc::text as block_end_utc, item.start_utc::text as start_utc')
            ->get();

        $items = [];

        foreach ($rows as $row) {
            if (! is_int($row->service_id) || ! is_int($row->staff_id) || ! is_int($row->price_minor)
                || ! is_string($row->currency) || ! is_string($row->block_start_utc) || ! is_string($row->block_end_utc)
                || ! is_string($row->start_utc)) {
                throw new LogicException(sprintf('A booking_items row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
            }

            $items[] = new SoldItem(
                $row->service_id,
                $row->staff_id,
                new StaffBlock($row->staff_id, SqlText::readInstant($row->block_start_utc), SqlText::readInstant($row->block_end_utc)),
                new Money($row->price_minor, Currency::of($row->currency)),
                SqlText::readInstant($row->start_utc),
            );
        }

        return $items;
    }

    /**
     * The items of one booking in chain order, each with the name of its service and of its
     * staff member, and whether that member is the tenant's implicit one. An item's price is
     * carried in the booking's currency: the row holds minor units alone, and the container is
     * where the currency lives.
     *
     * @return list<BookingItemSnapshot>
     */
    private function items(Tenant $tenant, int $bookingId, Currency $currency): array
    {
        $rows = $this->db->connection()
            ->table($tenant->schema_name.'.booking_items', 'item')
            ->join($tenant->schema_name.'.services as service', 'service.id', '=', 'item.service_id')
            ->join($tenant->schema_name.'.staff as member', 'member.id', '=', 'item.staff_id')
            ->where('item.booking_id', $bookingId)
            ->orderBy('item.sort')
            ->selectRaw('item.sort, item.service_id, service.name as service_name, item.staff_id, member.name as staff_name, member.is_implicit,'
                .' item.start_utc::text as start_utc, item.end_utc::text as end_utc, item.price_minor')
            ->get();

        $items = [];

        foreach ($rows as $row) {
            if (! is_int($row->sort) || ! is_int($row->service_id) || ! is_string($row->service_name) || ! is_int($row->staff_id)
                || ! is_string($row->staff_name) || ! is_bool($row->is_implicit) || ! is_string($row->start_utc)
                || ! is_string($row->end_utc) || ! is_int($row->price_minor)) {
                throw new LogicException(sprintf('A booking_items row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
            }

            $items[] = new BookingItemSnapshot(
                $row->sort,
                $row->service_id,
                $row->service_name,
                $row->staff_id,
                $row->staff_name,
                $row->is_implicit,
                SqlText::readInstant($row->start_utc),
                SqlText::readInstant($row->end_utc),
                new Money($row->price_minor, $currency),
            );
        }

        return $items;
    }
}
