<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The one home of the fixture's default start. Every fixture booking that names no start of
 * its own begins at START, an Athens wall-clock time on a Tuesday when Elena works 09:00 to
 * 13:00 and 14:00 to 18:00 and Athens is UTC+2: LunaFixture, SnapshotFixture, seamHold and the
 * file-local helpers all read the default from here. A test that hands in a start of its own
 * still spells the day where it stands, which a pinned clock allows.
 *
 * A literal date is only safe under a clock that does not move. The rule: a test that spells a
 * date binds a pinned clock before anything reads the time, and a test left on the system
 * clock spells its dates relative to today. pinClock() is the first half in one call, a clock
 * standing a known distance before START, so a booking taken at the default start and the
 * clock every policy reads agree whatever the real date is.
 */
final class FixtureDay
{
    public const string DATE = '2026-11-03';

    public const string START = self::DATE.' 10:00';

    public const string ZONE = 'Europe/Athens';

    /**
     * How far before START the pinned clock stands unless a test says otherwise: 26 hours,
     * which is 06:00 UTC on the Monday, outside the 24-hour cancellation and reschedule
     * windows, and the instant most of the lifecycle files already pin by hand.
     */
    public const string AHEAD = 'PT26H';

    /**
     * START as the instant it names.
     */
    public static function start(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::START, new DateTimeZone(self::ZONE));
    }

    /**
     * Binds a clock pinned the given elapsed time before START as the application's one Clock,
     * and hands it back for a test that wants to read the instant it stands on.
     */
    public static function pinClock(string $before = self::AHEAD): FixedClock
    {
        $clock = new FixedClock(self::start()->sub(new DateInterval($before)));

        app()->instance(Clock::class, $clock);

        return $clock;
    }
}
