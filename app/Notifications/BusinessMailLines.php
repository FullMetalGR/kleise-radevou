<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;

/**
 * One line of lang/{en,el}/business-mail.php, the words of the business's messages: read through
 * the translator in the language given, its placeholders filled from the values the caller hands
 * in, :business, :customer, :staff, :name, :from, :to, :end, :count or :label. A key the file
 * lacks reads as the key, as the translator answers it. It also reads one label of
 * lang/{en,el}/admin/emails.php, the approved message's by MessageKey::labelKey(), which the
 * auto-approved notice quotes.
 *
 * The language is always named and is the recipient's, the code the Recipient carries
 * (Languages::ofRecipient): never the application's locale, which a queued job has no request to
 * set, and never the booking's, which is the customer's.
 *
 * Static, as CustomerMailLines and DecisionLines are, because the templates that read it are
 * static and run in the Unit suite with a translator over the lang directory handed in.
 */
final readonly class BusinessMailLines
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function line(string $key, SiteLanguage $language, array $replace = []): string
    {
        $line = app(Translator::class)->get($key, $replace, $language->value);

        return is_string($line) ? $line : $key;
    }

    /**
     * One line read as a choice by a number, :count the number as digits, the way the line
     * spelled it before it was a key.
     *
     * @param  array<string, string>  $replace
     */
    public static function choice(string $key, int $count, SiteLanguage $language, array $replace = []): string
    {
        return app(Translator::class)->choice($key, $count, $replace, $language->value);
    }
}
