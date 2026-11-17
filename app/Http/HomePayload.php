<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\BookingRecord;
use DateTimeZone;

/**
 * Home's booking rows, Next up's, Awaiting approval's and To mark's: the booking's uuid, its
 * status, the customer's name (null once erased), each distinct service once in item order,
 * each distinct named member once (the implicit row stands for the business and is never
 * named), the first start and the hold's end, the instants in the API's spelling. No address,
 * no phone and no amount: a row is what Home draws.
 */
final readonly class HomePayload
{
    /**
     * @return array{uuid: string, status: string, customer: string|null, services: list<string>, staff: list<string>, starts_at: string, hold_expires_at: string|null}
     */
    public static function booking(BookingRecord $record): array
    {
        $booking = $record->snapshot;
        [$services, $staff] = self::names($record);

        return [
            'uuid' => $booking->uuid,
            'status' => $booking->status->value,
            'customer' => $booking->customer?->name,
            'services' => $services,
            'staff' => $staff,
            'starts_at' => BookingPayload::instant($booking->startsAt()),
            'hold_expires_at' => $booking->holdExpiresAt === null ? null : BookingPayload::instant($booking->holdExpiresAt),
        ];
    }

    /**
     * To mark's card: `count`, every booking to mark however many are listed; `thank_you`, whether
     * the tenant sends the thank-you email a Completed mark sets off, which the bulk confirm names
     * only where it goes; and `days`, the rows given in their order, grouped by the date the first
     * start falls on in the tenant's zone, each day its date, Y-m-d, whether it is the tenant's
     * today, and its rows as booking() shapes them.
     *
     * @param  list<BookingRecord>  $records
     * @return array{count: int, thank_you: bool, days: list<array{date: string, today: bool, bookings: list<array<string, mixed>>}>}
     */
    public static function toMark(array $records, int $count, DateTimeZone $zone, string $today, bool $thankYou): array
    {
        $days = [];

        foreach ($records as $record) {
            $date = $record->snapshot->startsAt()->setTimezone($zone)->format('Y-m-d');
            $days[$date] ??= ['date' => $date, 'today' => $date === $today, 'bookings' => []];
            $days[$date]['bookings'][] = self::booking($record);
        }

        return ['count' => $count, 'thank_you' => $thankYou, 'days' => array_values($days)];
    }

    /**
     * A booking's line as Home draws it beneath a row: each distinct service once in item order,
     * and each distinct named member once, the implicit row never named.
     *
     * @return array{list<string>, list<string>}
     */
    public static function names(BookingRecord $record): array
    {
        $services = [];
        $staff = [];

        foreach ($record->snapshot->items as $item) {
            $services[$item->serviceName] = true;

            if (! $item->staffIsImplicit) {
                $staff[$item->staffName] = true;
            }
        }

        return [array_map(strval(...), array_keys($services)), array_map(strval(...), array_keys($staff))];
    }
}
