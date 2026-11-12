<?php

declare(strict_types=1);

namespace App\Site;

/**
 * The six kinds of business the site has a page for, each served by the one template at
 * /for/{key} and /el/for/{key}, its copy the slots under its key in verticals.php and its
 * link's label under site.pages.vertical. The route admits these keys alone, in this order, and
 * any other value is the site's 404.
 */
enum Vertical: string
{
    case HairSalons = 'hair-salons';
    case Barbers = 'barbers';
    case Nails = 'nails';
    case Beauty = 'beauty';
    case Massage = 'massage';
    case Physiotherapy = 'physiotherapy';

    /**
     * Every key, in the order the footer, the home's strip and the sitemap list them.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (self $vertical): string => $vertical->value, self::cases());
    }
}
