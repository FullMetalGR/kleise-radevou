<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Domain\Money\Money;
use App\Site\SiteLanguage;
use NumberFormatter;

/**
 * How a booking is written out in a message: what each item is, who it is with where that is a
 * person, when it is in the tenant's clock, and what the whole of it came to.
 *
 * Both audiences read the same blocks. The customer and the business are told different things
 * about a booking, but the appointments themselves are one set of facts, and two renderings of
 * them would be two chances for a chain to be listed in a different order, an hour to be spelled
 * a different way, or a total to be rounded differently in the two mailboxes discussing it.
 *
 * What an item is called is ItemName's rule, because the calendar file names the same items and
 * has to name them the same way.
 *
 * Every item is timed. A chain's segments can sit hours apart with the staff member free in
 * between, so one time for the whole of it would be a time the later segments are not at.
 */
final readonly class BookingLines
{
    /**
     * Every item and its start, in the language given and the tenant's clock: the item named by
     * ItemName, the start spelled by TenantTime::instant.
     */
    public static function of(BookingSnapshot $booking, TenantClock $clock, SiteLanguage $language): string
    {
        $blocks = [];

        foreach ($booking->items as $item) {
            $blocks[] = sprintf("    %s\n    %s", ItemName::of($item, $language), TenantTime::instant($item->start, $clock, $language));
        }

        return implode("\n\n", $blocks);
    }

    /**
     * What the booking came to, in the currency the booking row carries and never the tenant's
     * current default: changing the setting must never restate a price somebody has already
     * agreed to. The customer reads it as what they owe and the business as what the morning is
     * worth, which is the same number and therefore one line, a line of decision.php in the
     * language given with the amount as :amount.
     */
    public static function total(BookingSnapshot $booking, SiteLanguage $language): string
    {
        return DecisionLines::line('decision.facts.total', $language, ['amount' => self::amount($booking->total, $language)]);
    }

    /**
     * An amount as the total spells it in a language: the number by ICU's decimal formatter in
     * the language, its separators the language's and exactly the currency's digits after the
     * decimal one, the scale read from the currency and never a divisor of a hundred, and the
     * currency's code after it, as the line has always named it, "150.00 EUR" in English. The
     * code and not a symbol: SiteMoney's precedent spells the pricing page's symbol, but a
     * booking's mails and pages name the row's currency by its code, which reads the same in both
     * languages. Where the formatter cannot spell the number, the plain decimal stands in.
     */
    private static function amount(Money $money, SiteLanguage $language): string
    {
        $digits = $money->currency->digits();
        $formatter = new NumberFormatter($language->value, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $spelled = $formatter->format((float) $money->decimal());

        return (is_string($spelled) ? $spelled : $money->decimal()).' '.$money->currency->code;
    }
}
