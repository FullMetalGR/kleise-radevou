<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\AvailableStarts;

/**
 * GET /availability on the wire: a JSON object keyed by tenant-local date, every date asked
 * for present in order, each holding its starts as RFC 3339 instants in UTC, ascending, an
 * empty list for a day with none. The client builds the strip from the keys and the grid from
 * one key's list, and never computes a date itself; the UTC spelling is what the client parses
 * into an instant and formats in the tenant's zone.
 *
 * The public read answers an empty window with one key more after its days:
 * next_available_from, the first later tenant-local date holding a start inside the chain's
 * horizon, or null. No date is spelled like it, so the client tells it from a day by its name.
 * The per-booking read and the admin's read never carry it.
 */
final readonly class AvailabilityPayload
{
    /**
     * The key an empty window's answer names its next day under, on the public read alone.
     */
    public const string NEXT_AVAILABLE_FROM = 'next_available_from';

    /**
     * @return array<string, list<string>>
     */
    public static function of(AvailableStarts $starts): array
    {
        $payload = [];

        foreach ($starts->byDay as $day => $instants) {
            $payload[$day] = array_map(BookingPayload::instant(...), $instants);
        }

        return $payload;
    }

    /**
     * An empty window's answer on the public read: every day asked for, as of() spells it, then
     * the next day holding a start, a tenant-local date, or null where none is found.
     *
     * @return array<string, list<string>|string|null>
     */
    public static function emptyWindow(AvailableStarts $starts, ?string $nextAvailableFrom): array
    {
        return [...self::of($starts), self::NEXT_AVAILABLE_FROM => $nextAvailableFrom];
    }
}
