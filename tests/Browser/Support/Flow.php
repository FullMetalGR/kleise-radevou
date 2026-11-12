<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Site\SiteLanguage;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;

/**
 * What every browser journey needs before it can press anything: the day it books on, the
 * instant the slot button carries, and the sentence the flow answers with when it is done.
 *
 * Here for the same reason FlowScripts is here. A Pest file's functions and constants are
 * global once the file is loaded, so two files cannot spell the same name, and what that
 * produces is the same helper copied under three names until one copy is fixed and the others
 * are not. A journey that books on a different day from the journey beside it is a difference
 * nobody chose.
 */
final class Flow
{
    /**
     * The key of what the done step says, and what the polite region announces when the booking
     * lands, read through said().
     */
    public const string CONFIRMED = 'done.sentences.confirmed';

    /**
     * A sentence of the booking bundle in English, read through the translator from
     * lang/en/booking.php as the bundle reads it, so a journey that clicks English spells no
     * sentence of its own.
     *
     * @param  array<string, string>  $replace
     */
    public static function said(string $key, array $replace = []): string
    {
        $line = __('booking.'.$key, $replace, 'en');

        if (! is_string($line) || $line === 'booking.'.$key) {
            throw new LogicException(sprintf('lang/en/booking.php holds no line at %s.', $key));
        }

        return $line;
    }

    /**
     * A sentence of the booking bundle in a language, read through the translator from that
     * language's booking.php as the bundle reads it: a page under /el answers in Greek, the
     * prefix naming its language.
     *
     * @param  array<string, string>  $replace
     */
    public static function saidIn(string $key, SiteLanguage $language, array $replace = []): string
    {
        $line = __('booking.'.$key, $replace, $language->value);

        if (! is_string($line) || $line === 'booking.'.$key) {
            throw new LogicException(sprintf('lang/%s/booking.php holds no line at %s.', $language->value, $key));
        }

        return $line;
    }

    /**
     * The words of the manage page's and a card's buttons in the order they render, as
     * FlowScripts::BUTTONS reads them, each by its key.
     */
    public static function buttons(string ...$keys): string
    {
        return implode('|', array_map(self::said(...), $keys));
    }

    /**
     * The day with the given ISO weekday in the second week of a tenant's week row, seven to
     * thirteen days on, at midnight in that tenant's own zone. Seven consecutive days hold
     * exactly one, and it lies wholly in the future whatever the clock reads, so the whole of
     * that day's hours are offered; a journey reaches its button through weekOf(). Whole days
     * on by the calendar, never by elapsed hours, because a clock change makes those two
     * different numbers.
     */
    public static function day(string $timezone, int $weekday): DateTimeImmutable
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->setTime(0, 0);

        for ($days = 7; $days <= 13; $days++) {
            $candidate = $today->add(new DateInterval(sprintf('P%dD', $days)));

            if ((int) $candidate->format('N') === $weekday) {
                return $candidate;
            }
        }

        throw new LogicException('Seven consecutive days hold every weekday once.');
    }

    /**
     * Takes a journey on the when step, or in the reschedule dialog, from the week row's first
     * week to its second, where every day that day() answers lies: waits for the first week's
     * seven days, presses Next week, and answers the page once the given day's button is in the
     * row and nothing is busy. The day is the caller's to press, and a start at or after noon
     * sits behind its period. The page arrives as whichever of the plugin's wrappers the journey
     * holds and comes back as the awaitable one, each standing in for the webpage it wraps, so
     * the types are said in the docblock alone.
     *
     * @param  Webpage|PendingAwaitablePage  $page
     * @return Webpage
     */
    public static function weekOf($page, DateTimeImmutable $day)
    {
        return $page->assertScript(FlowScripts::daysRendered(7), true)
            ->click('.kr-booking__next')
            ->assertScript(FlowScripts::dayRendered($day), true);
    }

    /**
     * One wall-clock reading on a tenant-local day, spelled as the wire spells a start: RFC 3339
     * in UTC, which is the value the slot button carries.
     */
    public static function start(DateTimeImmutable $day, int $hour, int $minute = 0): string
    {
        return $day->setTime($hour, $minute)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
    }
}
