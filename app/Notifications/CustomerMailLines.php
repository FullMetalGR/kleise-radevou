<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;
use NumberFormatter;

/**
 * One line of lang/{en,el}/customer-mail.php, the words of the customer's messages: read through
 * the translator in the language given, its placeholders filled from the values the caller hands
 * in, :customer, :business, :service, :zone, :from, :to or :link. A key the file lacks reads as
 * the key, as the translator answers it. The confirmation reads one line of booking.php here too,
 * the heading the review step shows the Important info under, which the two share as one key.
 *
 * The language is always named and is the booking's, bookings.language as the snapshot carries it:
 * never the tenant's default, which may have changed since the booking was made, and never the
 * application's locale, which a queued job has no request to set.
 *
 * Static, as DecisionLines is, because the templates that read it are static and run in the Unit
 * suite with a translator over the lang directory handed in.
 */
final readonly class CustomerMailLines
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
     * One line read as a choice by a number, :count the number spelled out in words by ICU in the
     * language the line is read in: the language given where the file holds the line in it, else
     * the translator's fallback, as the translator itself chooses, so a pending Greek file never
     * puts a Greek word into its English sentence. ICU's Greek spelling of some numbers, fifteen
     * among them, holds a soft hyphen inside the word, which a plain text part would carry to the
     * reader as a stray character, so it is taken out here.
     *
     * @param  array<string, string>  $replace
     */
    public static function spelledChoice(string $key, int $count, SiteLanguage $language, array $replace = []): string
    {
        $translator = app(Translator::class);
        $readIn = $translator->hasForLocale($key, $language->value) ? $language->value : $translator->getFallback();
        $spelled = (new NumberFormatter($readIn, NumberFormatter::SPELLOUT))->format($count);

        $words = is_string($spelled) ? str_replace("\u{00AD}", '', $spelled) : (string) $count;

        return $translator->choice($key, $count, [...$replace, 'count' => $words], $language->value);
    }

    /**
     * The calendar file's summary in a language: the booking's services in chain order, as the
     * messages beside the file list them, at the business. One whole line with its two
     * placeholders, never "at" spliced between two names, spelled here and handed to
     * IcsBuilder, which holds no translator for it.
     */
    public static function summary(BookingSnapshot $booking, string $tenantName, SiteLanguage $language): string
    {
        return self::line('customer-mail.calendar.summary', $language, ['service' => self::services($booking), 'business' => $tenantName]);
    }

    /**
     * The booking's services in chain order, as a message lists them in one line: the names as the
     * tenant typed them, one after the other.
     */
    public static function services(BookingSnapshot $booking): string
    {
        return implode(', ', array_map(fn (BookingItemSnapshot $item): string => $item->serviceName, $booking->items));
    }
}
