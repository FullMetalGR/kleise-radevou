<?php

declare(strict_types=1);

namespace App\Http;

use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;

/**
 * One line of lang/{en,el}/api.php, the API's refusals: read through the translator in the
 * language given, else in the application's locale, which is the request's language as
 * SetTenantLocale set it, the API's lang else the tenant default, under /admin the user's. Its
 * placeholders are filled from the code's own constants, :n an item's position and, through
 * choice(), :count a limit. A key the file lacks reads as the key, as the translator answers it.
 *
 * Static, because the envelope is built by static factories the exception handler, the limits and
 * the controllers share, and the hold body's rules and sentences are spelled by static methods two
 * form requests and the availability query share; each reads the translator the container holds.
 */
final readonly class ApiLines
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function line(string $key, array $replace = [], ?SiteLanguage $language = null): string
    {
        $line = app(Translator::class)->get($key, $replace, $language?->value);

        return is_string($line) ? $line : $key;
    }

    /**
     * A line read as a choice by the count given, :count the count.
     */
    public static function choice(string $key, int $count, ?SiteLanguage $language = null): string
    {
        return app(Translator::class)->choice($key, $count, [], $language?->value);
    }
}
