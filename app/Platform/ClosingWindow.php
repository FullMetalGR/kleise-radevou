<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\TrialDeadlines;
use App\Domain\Lifetime;
use App\Http\AdminLines;
use App\Notifications\TenantTime;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * The retention window a closing keeps an account for, and the admin's sentences about it: thirty
 * days counted from closing_at in UTC, as the closing email counts them and the console's detail
 * reads their end, and the day they end spelled in the tenant's clock with its weekday and its
 * month in words, ICU's in the reader's language, since 02/12/2026 is two different days to two
 * halves of the world (TenantTime's reason). Every day here is the server's, from closing_at or
 * the Clock port, and never the browser's. The sentences are keys of
 * lang/{locale}/admin/billing.php under closing, each one whole sentence read in the
 * application's locale, the request's, which is the owner's on every reader: the Billing screen,
 * the Danger zone, the subscription gate and the close's flash. The console reads the day alone,
 * in English. The mails' are mail.php's.
 *
 * The way back depends on the tenant's shape. A tenant with a plan to choose, in a trial, lapsed
 * or subscribed, chooses it on the Billing screen, whose checkout completed inside the window
 * reopens the account. A free tenant's Billing screen offers no plan, and the platform reopens
 * it, so its sentence names support.
 */
final readonly class ClosingWindow
{
    /** The Billing notice's and the Danger zone's sentence while closing, its first half, :day. */
    public const string CLOSES = 'admin/billing.closing.closes';

    public const string WAY_BACK = 'admin/billing.closing.way_back';

    /** A free tenant's way back, the support address as :address. */
    public const string WAY_BACK_FREE = 'admin/billing.closing.way_back_free';

    /** The freeze's refusal of a write while closing, its first half, :day. */
    public const string FROZEN = 'admin/billing.closing.frozen';

    /** The flash the close lands with, :day. */
    public const string CLOSED = 'admin/billing.closing.closed';

    /**
     * A closing tenant's closing_at, or null for a tenant that is not closing; a closing row with
     * no closing_at raises, never read as unknown.
     */
    public static function of(Tenant $tenant): ?DateTimeImmutable
    {
        if ($tenant->status !== TenantStatus::Closing) {
            return null;
        }

        return $tenant->closing_at ?? throw new LogicException(sprintf('Tenant "%s" is closing with no closing_at.', $tenant->slug));
    }

    /**
     * The instant the window ends: thirty days of elapsed time after the closing, in UTC.
     */
    public static function endOf(DateTimeImmutable $closingAt): DateTimeImmutable
    {
        return $closingAt->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval(TrialDeadlines::RETENTION_WINDOW));
    }

    /**
     * The window's length in whole days, TrialDeadlines::RETENTION_WINDOW as Lifetime reads an
     * interval, which the Danger zone's sentences count as :count.
     */
    public static function days(): int
    {
        return Lifetime::days(TrialDeadlines::RETENTION_WINDOW);
    }

    /**
     * The day the window ends in the tenant's clock, in a language: its weekday and its month in
     * the language's own words from ICU, "Wednesday 2 December 2026" in English. The admin's
     * callers name the language the user reads the admin in, the console's English, the console
     * staying English.
     */
    public static function dayOf(DateTimeImmutable $closingAt, DateTimeZone $zone, SiteLanguage $language): string
    {
        return TenantTime::spelledIn(self::endOf($closingAt), $zone, $language)['date'];
    }

    /**
     * The way back: a plan on the Billing screen, or, for a free tenant, support at the address
     * given.
     */
    public static function wayBack(?string $freeSupport): string
    {
        return $freeSupport === null ? AdminLines::line(self::WAY_BACK) : AdminLines::line(self::WAY_BACK_FREE, ['address' => $freeSupport]);
    }

    /**
     * The sentence of a closing account on the Billing screen and in the Danger zone: the day it
     * closes, that everything in it is deleted then, and the way back.
     */
    public static function notice(string $day, ?string $freeSupport): string
    {
        return self::withWayBack(AdminLines::line(self::CLOSES, ['day' => $day]), $freeSupport);
    }

    /**
     * The freeze's refusal while closing: that settings cannot be changed, the day, and the way
     * back.
     */
    public static function frozen(string $day, ?string $freeSupport): string
    {
        return self::withWayBack(AdminLines::line(self::FROZEN, ['day' => $day]), $freeSupport);
    }

    /**
     * A whole sentence and then the way back, one space between: the join notice() and frozen()
     * share.
     */
    private static function withWayBack(string $line, ?string $freeSupport): string
    {
        return $line.' '.self::wayBack($freeSupport);
    }

    /**
     * The flash a landed close answers with: the day the account closes.
     */
    public static function closed(string $day): string
    {
        return AdminLines::line(self::CLOSED, ['day' => $day]);
    }
}
