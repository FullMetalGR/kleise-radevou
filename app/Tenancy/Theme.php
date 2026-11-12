<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * The tenant's named theme: one lowercase word on the settings row naming a palette of three
 * tokens, the primary, the text on it and the focus ring, in light and in dark. The cases are the
 * column's CHECK in the same order: the migration that adds it restates words() in SQL and
 * TenantSchemaTest holds the two equal, so the CHECK, the reader and any validation share one list.
 * Neutral is the default and the admin's own grey; the other six each carry a colour.
 *
 * The theme has replaced the booking page's free accent colour, the one colour a tenant chooses:
 * the migration that added the column mapped each saved accent to its nearest theme through
 * nearest(), the one rule there is for it, and tenant migration 000040 dropped the accent column.
 */
enum Theme: string
{
    case Neutral = 'neutral';
    case Blue = 'blue';
    case Green = 'green';
    case Orange = 'orange';
    case Red = 'red';
    case Rose = 'rose';
    case Violet = 'violet';

    /** The theme every tenant starts on, the column's default. */
    public const self DEFAULT = self::Neutral;

    /**
     * How far apart the brightest and the dimmest channel of a colour must be, out of 255, before
     * it reads as a colour at all: below it a grey, a black or a white, which maps to neutral.
     */
    private const int LEAST_CHROMA = 26;

    /**
     * The light primary of each coloured theme, which nearest() measures an accent's hue
     * against. Neutral has none: its primary is a grey.
     */
    private const array PRIMARIES = [
        'blue' => '#2563eb',
        'green' => '#15803d',
        'orange' => '#c2410c',
        'red' => '#dc2626',
        'rose' => '#e11d48',
        'violet' => '#7c3aed',
    ];

    /**
     * The seven words, the CHECK's list in order.
     *
     * @return list<string>
     */
    public static function words(): array
    {
        return array_map(fn (self $theme): string => $theme->value, self::cases());
    }

    /** The theme a stored word names; a missing, unknown or misshapen word reads as neutral. */
    public static function read(mixed $word): self
    {
        return (is_string($word) ? self::tryFrom($word) : null) ?? self::DEFAULT;
    }

    /**
     * The theme nearest a hex accent colour: neutral for a null or unreadable value and for a
     * colour with little or no chroma; otherwise the coloured theme whose light primary is
     * nearest in hue around the colour wheel, the earlier in the list on a tie.
     */
    public static function nearest(?string $accent): self
    {
        $hue = $accent === null ? null : self::hueOf($accent);

        if ($hue === null) {
            return self::DEFAULT;
        }

        $best = self::DEFAULT;
        $bestDistance = INF;

        foreach (self::PRIMARIES as $word => $primary) {
            $distance = abs($hue - (self::hueOf($primary) ?? 0.0));
            $distance = min($distance, 360.0 - $distance);

            if ($distance < $bestDistance) {
                $best = self::from($word);
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** The hue of a #rgb or #rrggbb colour in degrees, null where it is not one or has too little chroma to have a hue. */
    private static function hueOf(string $hex): ?float
    {
        if (preg_match('/\A#([0-9a-f]{3}|[0-9a-f]{6})\z/i', $hex, $match) !== 1) {
            return null;
        }

        $digits = strlen($match[1]) === 3 ? preg_replace('/(.)/', '$1$1', $match[1]) ?? '' : $match[1];
        [$r, $g, $b] = array_map(hexdec(...), str_split($digits, 2));
        $max = max($r, $g, $b);
        $chroma = $max - min($r, $g, $b);

        if ($chroma < self::LEAST_CHROMA) {
            return null;
        }

        $sector = match ($max) {
            $r => fmod(($g - $b) / $chroma, 6.0),
            $g => ($b - $r) / $chroma + 2.0,
            default => ($r - $g) / $chroma + 4.0,
        };

        return fmod($sector * 60.0 + 360.0, 360.0);
    }
}
