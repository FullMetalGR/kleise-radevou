<?php

declare(strict_types=1);

namespace App\Platform;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Attributes\Config;

/**
 * The console's one clock: the zone config/console.php names, Europe/Athens by default. The
 * console is on no tenant's clock, so an instant on a console screen or in a message to an
 * operator is spelled here and nowhere else, in the console's own spelled-out English form, its
 * weekday, day, month, year and time: with the zone named in a message, and without it on a
 * screen, whose top bar names it once.
 *
 * The console stays English, so its spelling is its own and PHP's English names, where the
 * tenant's surfaces use ICU's spelling in a language (TenantTime).
 *
 * A zone the configuration misspells is refused when this is built, rather than read as some other
 * clock, and the application's provider builds one at boot, so the refusal fails the deploy's
 * first command rather than the console door's first request.
 */
final readonly class ConsoleTime
{
    /** The console's spelling of an instant, PHP's English weekday and month. */
    private const string SPELLING = 'l j F Y \\a\\t H:i';

    private DateTimeZone $zone;

    public function __construct(#[Config('console.timezone')] string $timezone)
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public function zone(): DateTimeZone
    {
        return $this->zone;
    }

    /**
     * An instant as the console's clock reads it, the zone named beside it, for a message to an
     * operator, where nothing else names the clock. The instant named its own zone when it was
     * built; only its place on the timeline is read.
     */
    public function stamp(DateTimeImmutable $instant): string
    {
        return sprintf('%s (%s)', $this->onScreen($instant), $this->zone->getName());
    }

    /**
     * An instant as a console screen spells it: the console's clock reading alone, since the
     * console's top bar names the zone once for every instant on the screen.
     */
    public function onScreen(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->zone)->format(self::SPELLING);
    }
}
