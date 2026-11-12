<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Contracts\Translation\Translator;
use NumberFormatter;

/**
 * One line of the admin's language files, lang/{en,el}/admin, read through the translator the
 * container holds in the request's language, the user's under /admin as SetTenantLocale set it,
 * its placeholders filled from the values given; a key the file lacks reads as the key, as the
 * translator answers it. Beside it a figure spelled in words by ICU in the language the key is
 * read in, which the image uploads' size sentences and the pages that name a size read.
 *
 * Static, because the admin's form requests spell their messages and rules in static methods the
 * controllers and the screens' props share, and the platform's closing window words its sentences
 * from static methods too; each reads the translator the container holds.
 */
final readonly class AdminLines
{
    /**
     * @param  array<string, string>  $values
     */
    public static function line(string $key, array $values = []): string
    {
        $line = app(Translator::class)->get($key, $values);

        return is_string($line) ? $line : $key;
    }

    /**
     * A figure spelled in words by ICU in the language the key is read in: the request's where
     * the file holds the key in it, else the translator's fallback. A page whose help names a
     * size reads it from here too (StaffController's photo_limit).
     */
    public static function spelled(string $key, int $figure): string
    {
        $translator = app(Translator::class);
        $locale = $translator->getLocale();
        $readIn = $translator->hasForLocale($key, $locale) ? $locale : $translator->getFallback();
        $spelled = (new NumberFormatter($readIn, NumberFormatter::SPELLOUT))->format($figure);

        return is_string($spelled) ? $spelled : (string) $figure;
    }
}
