<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;

/**
 * One line of lang/{en,el}/decision.php, the decision pages' words and the booking facts they
 * share with the business's messages: read through the translator in the language given, its
 * placeholders filled from the values the caller hands in, :business, :service, :staff, :amount
 * or :zone. A key the file lacks reads as the key, as the translator answers it.
 *
 * The language is always named, never the application's locale: a page names the request's, and
 * a message the language it is written in, which a queued job has no request to set.
 *
 * Static, because the facts are spelled by static helpers the pages and the messages share,
 * CustomerFacts, ItemName, BookingLines and TenantTime, each reading the translator the container
 * holds; a unit test of a template hands one in, built over the lang directory.
 */
final readonly class DecisionLines
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function line(string $key, SiteLanguage $language, array $replace = []): string
    {
        $line = app(Translator::class)->get($key, $replace, $language->value);

        return is_string($line) ? $line : $key;
    }
}
