<?php

use App\Site\SiteLanguage;
use App\Site\TermsVersion;

/*
 * The version of the terms a signup accepts: the day the English terms file's version line names,
 * read in one place by the form that line is written in, and the Greek file's version line pinned
 * to the same day as the formatter writes it in Greek, so the two lines and the day a row stores
 * cannot drift apart. Read on the real files.
 */

it('reads the day the English terms file\'s version line names, a calendar day', function () {
    $day = app(TermsVersion::class)->day();
    $parsed = DateTimeImmutable::createFromFormat('!'.TermsVersion::DAY, $day, new DateTimeZone('UTC'));

    expect($day)->toMatch('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/')
        ->and($parsed instanceof DateTimeImmutable ? $parsed->format(TermsVersion::DAY) : null)->toBe($day);
});

it('spells the day back as each language\'s terms file spells its version line, the English and the Greek alike', function () {
    $day = app(TermsVersion::class)->day();
    $walked = 0;

    foreach (SiteLanguage::cases() as $language) {
        expect(TermsVersion::spelled($day, $language))->toBe(__(TermsVersion::FILE.'.version', [], $language->value), $language->value);
        $walked++;
    }

    expect($walked)->toBe(count(SiteLanguage::cases()));
});

it('refuses a version line it cannot read with a sentence naming the file: {case}', function (string $line) {
    expect(fn () => TermsVersion::parse($line))->toThrow(UnexpectedValueException::class, 'lang/en/'.TermsVersion::FILE.'.php');
})->with([
    'no day at all' => ['not a day'],
    'an empty line' => [''],
    'a month alone' => ['November'],
    'a month no calendar has' => ['3 Smarch 2026'],
    'a day with words after it' => [fn (): string => TermsVersion::spelled(app(TermsVersion::class)->day(), SiteLanguage::English).' or so'],
]);

it('refuses to spell a stored day the calendar does not have, rather than rolling it into the next month', function () {
    $overflowing = substr(app(TermsVersion::class)->day(), 0, 8).'32';

    expect(fn () => TermsVersion::spelled($overflowing, SiteLanguage::English))->toThrow(InvalidArgumentException::class, '"'.$overflowing.'"');
});
