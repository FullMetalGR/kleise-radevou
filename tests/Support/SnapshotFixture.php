<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Domain\Enum\BookingStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A booking as a reader would hand it back, built from a few words for the template tests: no
 * database, no container, no tenant, so it runs in the Unit suite.
 *
 * Every item runs one hour from the hour given, on the day given in the zone given, and is
 * stored the way the rows store it, in UTC. The default day is the Tuesday the notification
 * fixtures use, 3 November 2026, when Athens is UTC+2, so an hour of 10 is 08:00 in the row and
 * a message that printed the row would say 08:00 where it should say 10:00.
 *
 * Three unit files carry a builder of their own of the same shape; this is the one for the
 * rest.
 */
final class SnapshotFixture
{
    public const string UUID = '7b7f0e5a-0000-4000-8000-000000000005';

    /**
     * @param  list<array{string, string, bool, int}>  $items  each as [service, staff, implicit, hour]
     * @param  string  $language  the language the booking was made in, as bookings.language holds it
     */
    public static function booking(
        BookingStatus $status = BookingStatus::Confirmed,
        array $items = [['Haircut', 'Elena Papadaki', false, 10]],
        string $day = FixtureDay::DATE,
        string $currency = 'EUR',
        string $timezone = 'Europe/Athens',
        ?string $phone = '+30 210 7654321',
        string $language = 'en',
    ): BookingSnapshot {
        $zone = new DateTimeZone($timezone);
        $utc = new DateTimeZone('UTC');
        $snapshots = [];

        foreach ($items as $sort => [$service, $staff, $implicit, $hour]) {
            $start = (new DateTimeImmutable($day.' 00:00', $zone))->add(new DateInterval(sprintf('PT%dH', $hour)));

            $snapshots[] = new BookingItemSnapshot(
                $sort,
                7 + $sort,
                $service,
                3 + $sort,
                $staff,
                $implicit,
                $start->setTimezone($utc),
                $start->add(new DateInterval('PT1H'))->setTimezone($utc),
                new Money(3500, Currency::of($currency)),
            );
        }

        return new BookingSnapshot(
            self::UUID,
            $status,
            null,
            null,
            new Customer('Casey Customer', 'casey@example.test', $phone),
            new Money(3500 * count($items), Currency::of($currency)),
            $snapshots,
            $language,
        );
    }

    /**
     * An Athens wall-clock time as the instant it names, for the start a move left.
     */
    public static function at(string $athens): DateTimeImmutable
    {
        return new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens'));
    }
}
