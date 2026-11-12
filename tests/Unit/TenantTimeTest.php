<?php

use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Site\SiteLanguage;
use Tests\Support\LangLines;

/*
 * How an instant is written in the tenant's clock. On a page of the tenant's host and in a
 * message about a booking: ICU's own medium date and short time in a language, joined as ICU
 * joins them, with no connector word of this repository's, the date in the order the tenant's
 * date format writes it and the hour on the dial its time format reads. For the platform's mails:
 * the date and the time apart, the weekday and the month from the formatter's own data, joined by
 * mail.php's own sentence. The Greek is compared with the formatter's answer, never with a
 * literal, since no file outside lang/el holds a Greek character; the English literals are ICU's
 * English, the proof of what an English reader is shown.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/**
 * ICU's medium date and short time for a locale and an hour cycle, read straight off the
 * formatter, for the Greek the repository cannot spell.
 */
function tenantTimeIcu(DateTimeImmutable $instant, string $locale): string
{
    return (string) (new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, new DateTimeZone('Europe/Athens'), IntlDateFormatter::GREGORIAN))->format($instant);
}

it('spells an instant on a 24-hour tenant writing the day first as ICU\'s medium date and short time: day first and 09:00 in English, ICU\'s Greek in Greek, no connector word', function () {
    $instant = new DateTimeImmutable('2026-11-03 07:00:00', new DateTimeZone('UTC'));
    $clock = new TenantClock(new DateTimeZone('Europe/Athens'), 'd/m/Y', 'H:i');
    $greek = TenantTime::instant($instant, $clock, SiteLanguage::Greek);

    expect(TenantTime::instant($instant, $clock, SiteLanguage::English))->toBe('3 Nov 2026, 09:00')
        ->and($greek)->toBe(tenantTimeIcu($instant, 'el-GR-u-hc-h23'))
        ->and($greek)->toEndWith(' 2026, 09:00')
        ->and($greek)->toStartWith('3 ')
        ->and($greek)->not->toContain('Nov')
        ->and(preg_match('/^[\x00-\x7F]*$/', $greek))->toBe(0)
        ->and(TenantTime::instant($instant, $clock, SiteLanguage::English))->not->toContain(' at ');
});

it('spells an instant on a 12-hour tenant on the twelve-hour dial in either language, the date still day first', function () {
    $morning = new DateTimeImmutable('2026-11-03 07:00:00', new DateTimeZone('UTC'));
    $afternoon = new DateTimeImmutable('2026-11-03 13:05:00', new DateTimeZone('UTC'));
    $clock = new TenantClock(new DateTimeZone('Europe/Athens'), 'd/m/Y', 'g:i A');

    expect(TenantTime::instant($morning, $clock, SiteLanguage::English))->toBe(tenantTimeIcu($morning, 'en-GB-u-hc-h12'))
        ->and(TenantTime::instant($morning, $clock, SiteLanguage::English))->toStartWith('3 Nov 2026, 9:00')
        ->and(TenantTime::instant($morning, $clock, SiteLanguage::English))->toEndWith('am')
        ->and(TenantTime::instant($afternoon, $clock, SiteLanguage::English))->toStartWith('3 Nov 2026, 3:05')
        ->and(TenantTime::instant($afternoon, $clock, SiteLanguage::English))->toEndWith('pm')
        ->and(TenantTime::instant($morning, $clock, SiteLanguage::Greek))->toBe(tenantTimeIcu($morning, 'el-GR-u-hc-h12'))
        ->and(TenantTime::instant($afternoon, $clock, SiteLanguage::Greek))->toBe(tenantTimeIcu($afternoon, 'el-GR-u-hc-h12'))
        ->and(TenantTime::instant($afternoon, $clock, SiteLanguage::Greek))->toContain(' 3:05')
        ->and(TenantTime::instant($afternoon, $clock, SiteLanguage::Greek))->not->toBe(TenantTime::instant($afternoon, new TenantClock(new DateTimeZone('Europe/Athens')), SiteLanguage::Greek));
});

it('writes the month first where the tenant\'s format puts it first, in English, and keeps the tenant\'s dial there too', function () {
    $instant = new DateTimeImmutable('2026-11-03 13:05:00', new DateTimeZone('UTC'));

    expect(TenantTime::instant($instant, new TenantClock(new DateTimeZone('Europe/Athens'), 'm/d/Y', 'H:i'), SiteLanguage::English))->toBe('Nov 3, 2026, 15:05')
        ->and(TenantTime::instant($instant, new TenantClock(new DateTimeZone('Europe/Athens'), 'm/d/Y', 'h:i a'), SiteLanguage::English))->toBe(tenantTimeIcu($instant, 'en-US-u-hc-h12'))
        ->and(TenantTime::instant($instant, new TenantClock(new DateTimeZone('Europe/Athens'), 'm/d/Y', 'h:i a'), SiteLanguage::English))->toStartWith('Nov 3, 2026, 3:05');
});

it('reads the clock asked for across a daylight-saving boundary, in the tenant\'s zone and never the row\'s', function () {
    $october = new DateTimeImmutable('2026-10-08 09:00:00', new DateTimeZone('UTC'));
    $november = new DateTimeImmutable('2026-11-05 09:00:00', new DateTimeZone('UTC'));
    $newYork = new TenantClock(new DateTimeZone('America/New_York'));

    expect(TenantTime::instant($october, $newYork, SiteLanguage::English))->toBe('8 Oct 2026, 05:00')
        ->and(TenantTime::instant($november, $newYork, SiteLanguage::English))->toBe('5 Nov 2026, 04:00')
        ->and(TenantTime::instant($november, $newYork, SiteLanguage::Greek))->toEndWith(' 2026, 04:00');
});

it('names the business\'s clock in a line of decision.php, the zone filled in', function () {
    $athens = new DateTimeZone('Europe/Athens');

    expect(TenantTime::footerForTheBusiness($athens, SiteLanguage::English))->toBe(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens']))
        ->and(TenantTime::footerForTheBusiness($athens, SiteLanguage::English))->toContain('Europe/Athens')
        // decision.php's Greek line, the zone filled in, and not the English one.
        ->and(TenantTime::footerForTheBusiness($athens, SiteLanguage::Greek))->toBe(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens'], SiteLanguage::Greek))
        ->and(TenantTime::footerForTheBusiness($athens, SiteLanguage::Greek))->not->toBe(LangLines::line('decision.facts.zone', ['zone' => 'Europe/Athens']));
});

it('spells an instant in the tenant\'s clock in a language for the platform\'s mails, the date and the time apart: the English weekday and month as PHP spells them, the Greek in the formatter\'s own words', function () {
    $instant = new DateTimeImmutable('2026-10-08 09:00:00', new DateTimeZone('UTC'));
    $athens = new DateTimeZone('Europe/Athens');
    $greek = TenantTime::spelledIn($instant, $athens, SiteLanguage::Greek);
    $formatter = new IntlDateFormatter('el', IntlDateFormatter::FULL, IntlDateFormatter::NONE, $athens, IntlDateFormatter::GREGORIAN, 'EEEE d MMMM y');

    expect(TenantTime::spelledIn($instant, $athens, SiteLanguage::English))->toBe(['date' => 'Thursday 8 October 2026', 'time' => '12:00'])
        ->and(TenantTime::spelledIn($instant, $athens, SiteLanguage::English)['date'])->toBe($instant->setTimezone($athens)->format('l j F Y'))
        ->and($greek['time'])->toBe('12:00')
        ->and($greek['date'])->toBe($formatter->format($instant))
        ->and($greek['date'])->not->toBe('Thursday 8 October 2026')
        ->and(preg_match('/^\p{Lu}\p{Ll}+ 8 \p{Lu}\p{Ll}+ 2026$/u', $greek['date']))->toBe(1)
        ->and(preg_match('/^[\x00-\x7F]*$/', $greek['date']))->toBe(0);
});

it('reads the clock asked for across a daylight-saving boundary for the platform\'s mails: New York four hours behind in October and five in November, in either language', function () {
    $october = new DateTimeImmutable('2026-10-08 09:00:00', new DateTimeZone('UTC'));
    $november = new DateTimeImmutable('2026-11-05 09:00:00', new DateTimeZone('UTC'));
    $newYork = new DateTimeZone('America/New_York');

    expect(TenantTime::spelledIn($october, $newYork, SiteLanguage::English))->toBe(['date' => 'Thursday 8 October 2026', 'time' => '05:00'])
        ->and(TenantTime::spelledIn($november, $newYork, SiteLanguage::English))->toBe(['date' => 'Thursday 5 November 2026', 'time' => '04:00'])
        ->and(TenantTime::spelledIn($november, $newYork, SiteLanguage::Greek)['time'])->toBe('04:00');
});
