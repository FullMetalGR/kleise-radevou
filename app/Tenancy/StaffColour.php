<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * A member's own calendar colour: one word of the Calendar's fixed palette, in the palette's
 * order, which the column's CHECK restates and TenantSchemaTest holds equal to words(). A member
 * with none is on Automatic, the spread of the palette over the ids the Calendar has always
 * drawn; the browser maps each word to its hue (resources/js/admin/lib/hue.ts), the one place a
 * member's hue is decided.
 */
enum StaffColour: string
{
    case Orange = 'orange';
    case SkyBlue = 'sky_blue';
    case Green = 'green';
    case Yellow = 'yellow';
    case Blue = 'blue';
    case Vermilion = 'vermilion';
    case Purple = 'purple';

    /**
     * The seven words, the CHECK's list in order.
     *
     * @return list<string>
     */
    public static function words(): array
    {
        return array_map(fn (self $colour): string => $colour->value, self::cases());
    }
}
