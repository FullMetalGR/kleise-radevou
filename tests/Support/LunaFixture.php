<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Dto\Customer;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\Intake;
use App\Application\Dto\SegmentChoice;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingSource;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Tenancy\Tenant;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * The demo tenant luna as a fixture for the transition suites: holds taken the way the booking
 * page takes them, rows written straight to the tables for the states no transition reaches,
 * and the three reads a transition test makes afterwards.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00. Every wall-clock time named here is Athens; everything that
 * reaches the database is UTC text with its offset written out.
 *
 * A booking that names no start begins at FixtureDay::START, a literal date, so a test taking
 * one binds a pinned clock first, FixtureDay::pinClock() for one; a test left on the system
 * clock hands in a start relative to today.
 *
 * The customer is given a name, an address and a phone that nothing else in the fixtures
 * shares, so the privacy assertion of tests/Pest.php has something distinctive to look for and
 * a test that means to leak one can do it on purpose.
 *
 * The confirm, cancel and release suites predate this and carry their own copies of the same
 * helpers. They can move onto these; nothing in this class is particular to one transition.
 */
final class LunaFixture
{
    public const string CUSTOMER_NAME = 'Perpetua Vandersnoot';

    public const string CUSTOMER_EMAIL = 'perpetua.vandersnoot@example.test';

    public const string CUSTOMER_PHONE = '+30 210 1234567';

    public const string ZONE = FixtureDay::ZONE;

    public static function tenant(): Tenant
    {
        return Tenant::query()->where('slug', 'luna')->sole();
    }

    /**
     * A row of luna's catalog by its name: a service or a staff member.
     */
    public static function id(string $table, string $name): int
    {
        $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

        if (! is_int($id)) {
            throw new LogicException(sprintf('luna has no %s named "%s".', $table, $name));
        }

        return $id;
    }

    /**
     * A hold taken the way the booking page takes one: the services in chain order on the one
     * member, starting at the Athens wall-clock time given. A service that requires approval
     * lands the booking awaiting_approval on an approval hold, which is what the owner decides
     * on.
     *
     * @param  non-empty-list<string>  $services
     * @return string the uuid of the booking it left
     */
    public static function hold(array $services = ['Haircut'], string $start = FixtureDay::START, string $staff = 'Elena Papadaki'): string
    {
        return app(HoldBookingFactory::class)->for(self::tenant(), Pool::Whole)->hold(
            new HoldRequest(
                new Customer(self::CUSTOMER_NAME, self::CUSTOMER_EMAIL, self::CUSTOMER_PHONE),
                array_map(fn (string $service): SegmentChoice => new SegmentChoice(self::id('services', $service), self::id('staff', $staff)), $services),
                self::at($start),
                new Intake(BookingSource::Embed),
            ),
            Actor::customer(),
        )->uuid;
    }

    /**
     * A booking of luna written straight to the rows, in the state the test names, with one
     * item on Elena over the Athens hour given. The states no transition leads into are
     * reachable no other way, and a guarded transition reads no catalog and no mask, so a row
     * in the state is the whole fixture.
     *
     * The expiry is set against the database's own clock and not the test's, because whether a
     * hold is still live is the server's comparison inside the re-read.
     *
     * @param  string  $expiry  past, future, or none
     * @param  int|null  $itemId  an id for the item where a test needs one the sequence would
     *                            not draw; an explicit id does not advance the sequence, so a
     *                            high one sits above every row the sequence numbers after it
     * @return string the uuid
     */
    public static function booking(
        string $status,
        ?string $holdClass = null,
        string $expiry = 'none',
        string $start = FixtureDay::START,
        int $minutes = 45,
        bool $blocks = true,
        string $service = 'Haircut',
        string $paymentMode = 'onsite',
        bool $requiresApproval = false,
        string $onApprovalTimeout = 'expire',
        ?int $itemId = null,
    ): string {
        $uuid = (string) Str::uuid();
        $from = self::at($start);
        $to = $from->add(new DateInterval(sprintf('PT%dM', $minutes)));

        $bookingId = DB::table('tenant_luna.bookings')->insertGetId([
            'source' => 'embed',
            'uuid' => $uuid,
            'status' => $status,
            'hold_class' => $holdClass,
            'hold_expires_at' => match ($expiry) {
                'past' => DB::raw("now() - interval '1 minute'"),
                'future' => DB::raw("now() + interval '2 days'"),
                'none' => null,
                default => throw new LogicException(sprintf('An expiry is past, future or none, not "%s".', $expiry)),
            },
            'customer_name' => self::CUSTOMER_NAME,
            'customer_email' => self::CUSTOMER_EMAIL,
            'customer_phone' => self::CUSTOMER_PHONE,
            'total_minor' => 3500,
            'currency' => 'EUR',
            'payment_mode' => $paymentMode,
            'requires_approval' => $requiresApproval,
            'on_approval_timeout' => $onApprovalTimeout,
            'manage_token_hash' => str_repeat('c', 64),
        ]);

        DB::table('tenant_luna.booking_items')->insert([
            ...($itemId === null ? [] : ['id' => $itemId]),
            'booking_id' => $bookingId,
            'sort' => 0,
            'service_id' => self::id('services', $service),
            'staff_id' => self::id('staff', 'Elena Papadaki'),
            'start_utc' => self::instant($from),
            'end_utc' => self::instant($to),
            'block_start_utc' => self::instant($from),
            'block_end_utc' => self::instant($to),
            'price_minor' => 3500,
            'blocks' => $blocks,
        ]);

        return $uuid;
    }

    /**
     * The booking as the rows stand, every instant in UTC text, with the blocking flag of each
     * item in chain order.
     *
     * @return array<string, mixed>
     */
    public static function row(string $uuid): array
    {
        $row = DB::selectOne(
            'select status, hold_class, hold_expires_at::text as hold_expires_at, approved_at::text as approved_at,'
            .' approved_by, rejection_reason, payment_mode, total_minor'
            .' from tenant_luna.bookings where uuid = ?::uuid',
            [$uuid],
        );

        if (! $row instanceof stdClass) {
            throw new LogicException(sprintf('luna has no booking %s.', $uuid));
        }

        $blocking = [];

        foreach (DB::select(
            'select i.blocks from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
            .' where b.uuid = ?::uuid order by i.sort',
            [$uuid],
        ) as $item) {
            if (! $item instanceof stdClass || ! is_bool($item->blocks)) {
                throw new LogicException('A booking item came back without its blocking flag.');
            }

            $blocking[] = $item->blocks;
        }

        /** @var array<string, mixed> $booking */
        $booking = [...get_object_vars($row), 'blocks' => $blocking];

        return $booking;
    }

    /**
     * The trail of luna, oldest first, each row with the booking it names and its payload
     * decoded and sorted, so a test names the keys in one order whatever order they were
     * written in.
     *
     * @return list<array<mixed>>
     */
    public static function trail(): array
    {
        $rows = [];

        foreach (DB::select(
            'select a.actor, a.action, a.payload::text as payload, b.uuid::text as uuid'
            .' from tenant_luna.audit_log a join tenant_luna.bookings b on b.id = a.booking_id order by a.id'
        ) as $row) {
            if (! $row instanceof stdClass || ! is_string($row->payload)) {
                throw new LogicException('An audit row came back without its payload.');
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            ksort($payload);

            $values = get_object_vars($row);
            $values['payload'] = $payload;

            $rows[] = $values;
        }

        return $rows;
    }

    /**
     * What the closure threw as a refusal. Anything else, or nothing, fails the test.
     */
    public static function refusal(Closure $run): TransitionRefused
    {
        try {
            $run();
        } catch (TransitionRefused $refused) {
            return $refused;
        }

        Assert::fail('No TransitionRefused was thrown.');
    }

    /**
     * A connection of its own to the same database, for asking what another connection has
     * committed while a listener runs, and for moving a booking while a transition stands at a
     * checkpoint.
     */
    public static function probe(string $name): Connection
    {
        /** @var array<string, mixed> $pgsql */
        $pgsql = config('database.connections.pgsql');
        config(['database.connections.'.$name => $pgsql]);

        return DB::connection($name);
    }

    /**
     * An Athens wall-clock time as the instant it names.
     */
    public static function at(string $athens): DateTimeImmutable
    {
        return new DateTimeImmutable($athens, new DateTimeZone(self::ZONE));
    }

    /**
     * An instant as the server writes one: UTC text with the offset written out, never a bound
     * date object, which the framework formats without its zone.
     */
    public static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
