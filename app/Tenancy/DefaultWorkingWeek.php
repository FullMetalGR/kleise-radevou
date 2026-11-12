<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * The working week a tenant starts with: Monday to Friday, 09:00 to 17:00, on the implicit staff
 * member, which is what the owner sees as opening hours: a weekly shift pattern. Written once,
 * when that member holds no pattern at all, so a second provisioning run adds nothing and a
 * tenant that edited its hours keeps every edit, a shorter week included.
 */
final readonly class DefaultWorkingWeek
{
    public const string START = '09:00:00';

    public const string END = '17:00:00';

    /**
     * ISO weekdays, Monday to Friday.
     */
    public const array WEEKDAYS = [1, 2, 3, 4, 5];

    /**
     * The week as windows in the shape a weekly shift pattern is written from, in weekday order.
     *
     * @return list<array{weekday: int, start: string, end: string}>
     */
    public static function windows(): array
    {
        return array_map(
            fn (int $weekday): array => ['weekday' => $weekday, 'start' => self::START, 'end' => self::END],
            self::WEEKDAYS,
        );
    }
}
