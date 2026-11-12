<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Site\SiteLanguage;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use RuntimeException;

/**
 * How an instant is written on a page of the tenant's host and in a message: in the tenant's
 * clock, spelled in a language, with the zone named beside it once.
 *
 * There is one clock and it is the tenant's. A customer three time zones away is told the hour
 * the salon will be expecting them, because that is the hour the salon means; converting to the
 * reader's clock would be two answers to one question and neither of them is what the diary
 * says.
 *
 * The month is spelled in words. A message is read once, often on a phone, and 03/11/2026 is the
 * third of November to half the world and the eleventh of March to the other half. The words are
 * ICU's in the language: no weekday, month or connector word is spelled in this repository.
 */
final readonly class TenantTime
{
    /**
     * An instant as the tenant's clock reads it, in a language: ICU's own medium date and short
     * time, joined as ICU joins them, with no connector word of ours. The zone is set rather than
     * assumed, the instant naming its own zone when it was built.
     *
     * The date's order and the hour's dial are the tenant's: ICU's bare en is the United States',
     * month first on the twelve-hour dial, and bare el reads the twelve-hour dial too, where the
     * tenant's provisioned formats write the day first on the 24-hour clock. So the formatter is
     * asked in a locale of the language whose region writes the tenant's order, English day first
     * as Britain writes it and month first as the United States does, Greek as Greece writes it,
     * its one order day first; and the tenant's dial rides as the locale's hour cycle, h23 for a
     * 24-hour format and h12 for a twelve-hour one (TenantClock). The default tenant reads "3 Nov
     * 2026, 09:00" in English.
     */
    public static function instant(DateTimeImmutable $instant, TenantClock $clock, SiteLanguage $language): string
    {
        $region = match ($language) {
            SiteLanguage::English => $clock->dayFirst() ? 'GB' : 'US',
            SiteLanguage::Greek => 'GR',
        };
        $locale = sprintf('%s-%s-u-hc-%s', $language->value, $region, $clock->twelveHour() ? 'h12' : 'h23');
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, $clock->zone, IntlDateFormatter::GREGORIAN);

        // The dial is written into the pattern as well. An older ICU reads the locale's hour
        // cycle for a skeleton and not for a style, and would leave the region's own dial in the
        // short time. So the short time's part of the pattern is swapped for the locale's best
        // pattern for the hour and the minute on the tenant's dial, which is what a current ICU
        // wrote there already: where the dial held, nothing changes.
        $pattern = $formatter->getPattern();
        $short = (new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $clock->zone, IntlDateFormatter::GREGORIAN))->getPattern();
        $dial = (new IntlDatePatternGenerator($locale))->getBestPattern($clock->twelveHour() ? 'hm' : 'Hm');

        if (is_string($pattern) && is_string($short) && is_string($dial)) {
            $formatter->setPattern(str_replace($short, $dial, $pattern));
        }

        return self::spelled($formatter, $instant, $language);
    }

    /**
     * The same instant in the tenant's clock, in a language, for the platform's mails: the
     * date, its weekday and its month in the language's own words from the formatter's data and
     * spelled nowhere in this repository, and the time, as two parts. A sentence of mail.php
     * joins them, since the word between them is the language's too. The English date reads as
     * PHP's "l j F Y" spells it, the closing window's day among its readers
     * (ClosingWindow::dayOf). The zone is set as instant() sets it, and only the instant's
     * place on the timeline is read.
     *
     * @return array{date: string, time: string}
     */
    public static function spelledIn(DateTimeImmutable $instant, DateTimeZone $zone, SiteLanguage $language): array
    {
        return [
            'date' => self::formatted($instant, $zone, $language, 'EEEE d MMMM y'),
            'time' => self::formatted($instant, $zone, $language, 'HH:mm'),
        ];
    }

    private static function formatted(DateTimeImmutable $instant, DateTimeZone $zone, SiteLanguage $language, string $pattern): string
    {
        return self::spelled(new IntlDateFormatter($language->value, IntlDateFormatter::FULL, IntlDateFormatter::FULL, $zone, IntlDateFormatter::GREGORIAN, $pattern), $instant, $language);
    }

    private static function spelled(IntlDateFormatter $formatter, DateTimeImmutable $instant, SiteLanguage $language): string
    {
        $spelled = $formatter->format($instant);

        if (! is_string($spelled)) {
            throw new RuntimeException(sprintf('The formatter could not spell an instant in %s: %s', $language->value, $formatter->getErrorMessage()));
        }

        return $spelled;
    }

    /**
     * The line that names the clock, once, at the foot of a message to the customer, in the
     * language given: customer-mail.php's zone line, the zone and the business filled in.
     *
     * Every instant above it is in that clock, and a reader three time zones away has no way to
     * know which one unless a message says so. It is one sentence in one place, so that the
     * customer's messages cannot come to disagree about whose hours they have been quoting.
     */
    public static function footer(DateTimeZone $zone, string $tenantName, SiteLanguage $language): string
    {
        return CustomerMailLines::line('customer-mail.zone', $language, ['zone' => $zone->getName(), 'business' => $tenantName]);
    }

    /**
     * The same line for a page or a message to the business, where the clock is their own and
     * naming them in the third person would be telling somebody their own address: a line of
     * decision.php in the language given, the zone filled in (the zone is named by the page's own
     * line and never beside an instant).
     */
    public static function footerForTheBusiness(DateTimeZone $zone, SiteLanguage $language): string
    {
        return DecisionLines::line('decision.facts.zone', $language, ['zone' => $zone->getName()]);
    }
}
