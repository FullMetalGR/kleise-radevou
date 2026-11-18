<?php

use App\Site\SiteLanguage;

/*
 * The lines that name a day in the warning of a move onto closed time: one for each weekday in
 * each language, filled with the date. Greek says a day behind an article that follows the
 * weekday, so each Greek line opens with its own, then the weekday as the calendar names it in
 * Greek, then the date; the English line is the weekday and the date. A Greek sentence that takes
 * a day reads on from it with no colon before it and no word of its own between, a window of hours
 * follows the day behind a comma, and a run of days opens with its preposition.
 *
 * The dates are a week handed to the translator for the lines of their weekdays: no use case runs
 * here and nothing asks a clock.
 */

/** Monday 23 to Sunday 29 November 2026, each under the key of its weekday. */
const DAY_LINES_WEEK = [
    'monday' => '2026-11-23',
    'tuesday' => '2026-11-24',
    'wednesday' => '2026-11-25',
    'thursday' => '2026-11-26',
    'friday' => '2026-11-27',
    'saturday' => '2026-11-28',
    'sunday' => '2026-11-29',
];

/** The article each Greek weekday is said behind: six of them feminine, Saturday neuter. */
const DAY_LINES_ARTICLES = [
    'monday' => "\u{03C4}\u{03B7}",
    'tuesday' => "\u{03C4}\u{03B7}\u{03BD}",
    'wednesday' => "\u{03C4}\u{03B7}\u{03BD}",
    'thursday' => "\u{03C4}\u{03B7}\u{03BD}",
    'friday' => "\u{03C4}\u{03B7}\u{03BD}",
    'saturday' => "\u{03C4}\u{03BF}",
    'sunday' => "\u{03C4}\u{03B7}\u{03BD}",
];

/** The day of a weekday in the week above, at midnight UTC. */
function dayLinesDate(string $weekday): DateTimeImmutable
{
    return new DateTimeImmutable(DAY_LINES_WEEK[$weekday], new DateTimeZone('UTC'));
}

/**
 * A line of the move's warning in Greek.
 *
 * @param  array<string, string>  $replace
 */
function dayLinesGreek(string $key, array $replace = []): string
{
    return adminLine('admin/bookings.move.confirm.'.$key, $replace, SiteLanguage::Greek);
}

/** The line of a weekday in a language, filled with the date of that weekday in the week above. */
function dayLinesDay(string $weekday, SiteLanguage $language): string
{
    return adminLine('admin/bookings.move.confirm.days.'.$weekday, ['date' => dayLinesDate($weekday)->format('d/m/Y')], $language);
}

it('names a weekday in Greek behind its article and in English bare, the date after it', function (string $weekday) {
    $date = dayLinesDate($weekday);
    // The weekday as ICU names it in Greek, computed here and never spelled.
    $named = (string) (new IntlDateFormatter(SiteLanguage::Greek->value, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'EEEE'))->format($date);

    expect(strtolower($date->format('l')))->toBe($weekday)
        ->and($named)->not->toBe('')
        ->and($named)->not->toBe($date->format('l'))
        ->and(dayLinesDay($weekday, SiteLanguage::Greek))->toBe(DAY_LINES_ARTICLES[$weekday].' '.$named.' '.$date->format('d/m/Y'))
        ->and(dayLinesDay($weekday, SiteLanguage::English))->toBe($date->format('l d/m/Y'));
})->with(array_keys(DAY_LINES_WEEK));

it('holds a line for each of the seven weekdays in both languages, Monday first', function (SiteLanguage $language) {
    /** @var array{move: array{confirm: array{days: array<string, string>, weekdays: array<string, string>}}} $bookings */
    $bookings = require lang_path($language->value.'/admin/bookings.php');

    expect(array_keys($bookings['move']['confirm']['days']))->toBe(array_keys(DAY_LINES_WEEK))
        ->and(array_keys($bookings['move']['confirm']['weekdays']))->toBe(array_keys(DAY_LINES_WEEK));
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('reads a Greek sentence on from the day with no colon before it, on every weekday', function (string $weekday) {
    $day = dayLinesDay($weekday, SiteLanguage::Greek);
    $when = dayLinesGreek('on_day', ['day' => $day]);
    $sentences = [
        dayLinesGreek('business_closed', ['when' => $when]) => $day.'.',
        dayLinesGreek('business_closed_reason', ['when' => $when, 'reason' => 'Stocktake']) => $day.' (Stocktake).',
        dayLinesGreek('staff_closed', ['staff' => 'Elena Papadaki', 'when' => $when]) => $day.'.',
        dayLinesGreek('staff_closed_reason', ['staff' => 'Elena Papadaki', 'when' => $when, 'reason' => 'Training']) => $day.' (Training).',
        dayLinesGreek('off_date', ['staff' => 'Elena Papadaki', 'when' => $when]) => $day.'.',
    ];

    expect($when)->toBe($day)
        ->and($sentences)->toHaveCount(5);

    foreach ($sentences as $sentence => $ending) {
        // No colon covers both: none set before the day, and no word left unfilled behind one.
        expect($sentence)->toEndWith(' '.$ending)
            ->and($sentence)->not->toContain(':');
    }
})->with(array_keys(DAY_LINES_WEEK));

it('says a Greek day whole, its hours behind a comma and a run of days behind its preposition', function () {
    $monday = dayLinesDay('monday', SiteLanguage::Greek);
    $tuesday = dayLinesDay('tuesday', SiteLanguage::Greek);
    $wednesday = dayLinesDay('wednesday', SiteLanguage::Greek);
    $from = "\u{03B1}\u{03C0}\u{03CC}";
    $to = "\u{03AD}\u{03C9}\u{03C2}";

    expect($tuesday)->toBe("\u{03C4}\u{03B7}\u{03BD} \u{03A4}\u{03C1}\u{03AF}\u{03C4}\u{03B7} 24/11/2026")
        ->and(dayLinesDay('saturday', SiteLanguage::Greek))->toBe("\u{03C4}\u{03BF} \u{03A3}\u{03AC}\u{03B2}\u{03B2}\u{03B1}\u{03C4}\u{03BF} 28/11/2026")
        ->and(dayLinesGreek('on_day_window', ['day' => $tuesday, 'start' => '10:00', 'end' => '12:00']))->toBe($tuesday.', 10:00 '.$to.' 12:00')
        ->and(dayLinesGreek('run', ['day' => $monday, 'until' => $wednesday]))->toBe($from.' '.$monday.' '.$to.' '.$wednesday)
        ->and(dayLinesGreek('run_window', ['day' => $monday, 'until' => $wednesday, 'start' => '10:00', 'end' => '12:00']))->toStartWith($from.' '.$monday.' '.$to.' '.$wednesday.', 10:00 '.$to.' 12:00 ');
});
