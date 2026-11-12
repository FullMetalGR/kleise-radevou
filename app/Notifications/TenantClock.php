<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Tenancy\Settings;
use App\Tenancy\Tenant;
use DateTimeZone;

/**
 * The clock a tenant keeps, as its instants are spelled on a page of its host or in a message
 * about one of its bookings: its zone, and the date and time formats its settings row holds
 * (PHP date tokens fixed at provisioning). There is one clock and it is the tenant's; the
 * formats say in which order the tenant writes a day and on which dial it reads the hour, and
 * they stay as they are while the words change with the language.
 *
 * The formats are read for those two facts and nothing else, by TenantTime::instant: where the
 * date format puts the day before the month, and whether the time format names a twelve-hour
 * hour. A format with neither the day nor the month reads day first, and one naming no hour of
 * either dial reads the 24-hour clock, the formats every tenant is provisioned with.
 */
final readonly class TenantClock
{
    public function __construct(
        public DateTimeZone $zone,
        public string $dateFormat = Settings::DATE_FORMAT,
        public string $timeFormat = Settings::TIME_FORMAT,
    ) {}

    /**
     * The clock of a tenant, its zone off the tenant's row and its formats off its settings row.
     */
    public static function of(Tenant $tenant, Settings $settings): self
    {
        return new self($tenant->zone(), $settings->dateFormat, $settings->timeFormat);
    }

    /**
     * Whether the tenant writes the day before the month, PHP's d or j ahead of its m, n, M or F.
     */
    public function dayFirst(): bool
    {
        $tokens = self::tokens($this->dateFormat);
        $day = strcspn($tokens, 'dj');
        $month = strcspn($tokens, 'mnMF');

        return $day <= $month;
    }

    /**
     * Whether the tenant reads the hour on the twelve-hour dial, PHP's g or h.
     */
    public function twelveHour(): bool
    {
        return strpbrk(self::tokens($this->timeFormat), 'gh') !== false;
    }

    /**
     * The format's tokens, every escaped character taken out, so a literal letter is no token.
     */
    private static function tokens(string $format): string
    {
        return (string) preg_replace('/\\\\./s', '', $format);
    }
}
