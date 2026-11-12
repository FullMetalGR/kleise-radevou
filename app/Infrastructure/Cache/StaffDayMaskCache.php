<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Infrastructure\Db\NotBookedMask;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;

/**
 * The mask cache, and the one place a reader and a writer agree on its key and its stored form.
 * The availability read looks entries up here after fetching the revisions of the mutex rows;
 * the hold stores the post-insert masks here after its commit under the revisions the lock
 * returned. Nothing inside a transaction touches this.
 *
 * The key names the tenant schema, the staff member, the day, the granularity and the revision.
 * A staff-day with no mutex row is keyed as absent, a value of its own distinct from zero: the
 * lock creates a missing row at zero rather than one, so an entry made before a staff-day's
 * first booking must not match the key its first booking makes. The tenant schema is in the
 * key because two tenants share staff ids and days and must never share an entry, and the
 * granularity because it fixes the width the words are read at.
 *
 * The stored form is one ASCII string: the width, the words packed big-endian in base64, and
 * the earliest hold expiry or nothing. Exact, since the words are the mask; compact, forty
 * bytes for a day at five minutes before the base64; and portable, since the byte order is
 * fixed rather than the host's. Anything that does not decode to the width asked for is a miss.
 *
 * An entry lives one day at most, so an abandoned key leaves on its own, and no longer than
 * the earliest expiry among the holds it counted as busy, an auto-approving request's window
 * among them: a hold that lapses by the clock bumps no revision, and the sweeper bumps nothing
 * either, on purpose, not even where it grants such a request into a payment hold of its own
 * expiry, so no entry built while the request waited may outlive its window. The expiry is
 * stored beside the mask as well as setting the lifetime, and the reader judges it against the
 * clock it was handed, the database's now from the revisions statement, so an entry is a miss
 * the instant the predicate would stop counting the hold or the window closes, whatever clock
 * the store itself keeps.
 *
 * The store is the framework's cache repository, whichever the deployment configures: array in
 * the test suite. A null store makes every read cold: the cache is an optimisation only, and a
 * cold read answers the same bytes.
 */
final readonly class StaffDayMaskCache
{
    public const int LIFETIME_SECONDS = 86_400;

    private const string PREFIX = 'staff-day-mask';

    private const string ABSENT = 'none';

    private const string SEPARATOR = ';';

    private const string INSTANT = 'Y-m-d H:i:s.uP';

    public function __construct(private Repository $store) {}

    public static function key(string $schemaName, int $staffId, string $day, Granularity $granularity, ?int $revision): string
    {
        return implode(':', [
            self::PREFIX,
            $schemaName,
            (string) $staffId,
            $day,
            (string) $granularity->minutes,
            $revision === null ? self::ABSENT : (string) $revision,
        ]);
    }

    /**
     * The entries the store still holds for the keys, in one round trip, each read at the
     * width its staff-day has; an entry that does not decode to that width, or whose earliest
     * hold expiry is at or before now, is a miss and left out.
     *
     * @param  array<string, int>  $widthByKey  the width of each key's staff-day
     * @return array<string, NotBookedMask> the hits, keyed as asked
     */
    public function fetch(array $widthByKey, DateTimeImmutable $now): array
    {
        if ($widthByKey === []) {
            return [];
        }

        $hits = [];

        foreach ($this->store->many(array_keys($widthByKey)) as $key => $stored) {
            $key = (string) $key;

            if (! is_string($stored) || ! isset($widthByKey[$key])) {
                continue;
            }

            $entry = self::decode($stored, $widthByKey[$key]);

            if ($entry === null || $entry->lapsedBy($now)) {
                continue;
            }

            $hits[$key] = $entry;
        }

        return $hits;
    }

    /**
     * Stores an entry for the lifetime it earns from now; one whose earliest hold expiry has
     * already passed is not stored at all.
     */
    public function store(string $key, NotBookedMask $entry, DateTimeImmutable $now): void
    {
        $seconds = self::lifetime($entry, $now);

        if ($seconds > 0) {
            $this->store->put($key, self::encode($entry), $seconds);
        }
    }

    /**
     * The whole seconds an entry may live from now: one day, or the time until its earliest
     * hold expiry when that comes first, the fraction of a second dropped so that an entry
     * never outlives the expiry; zero once the expiry has passed.
     */
    public static function lifetime(NotBookedMask $entry, DateTimeImmutable $now): int
    {
        $expiry = $entry->earliestHoldExpiry;

        if ($expiry === null) {
            return self::LIFETIME_SECONDS;
        }

        $micros = ($expiry->getTimestamp() - $now->getTimestamp()) * 1_000_000
            + ((int) $expiry->format('u') - (int) $now->format('u'));

        return max(0, min(self::LIFETIME_SECONDS, intdiv($micros, 1_000_000)));
    }

    public static function encode(NotBookedMask $entry): string
    {
        return implode(self::SEPARATOR, [
            (string) $entry->mask->width,
            base64_encode(pack('J*', ...$entry->mask->toWords())),
            $entry->earliestHoldExpiry === null ? '' : $entry->earliestHoldExpiry->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT),
        ]);
    }

    /**
     * The entry a stored form spells at the width, or null for anything else: another width,
     * a malformed string, words that do not fit, a bit past the width, an unreadable expiry.
     */
    public static function decode(string $stored, int $width): ?NotBookedMask
    {
        $parts = explode(self::SEPARATOR, $stored);

        if (count($parts) !== 3 || $parts[0] !== (string) $width) {
            return null;
        }

        $bytes = base64_decode($parts[1], true);

        if ($bytes === false || $bytes === '' || strlen($bytes) % 8 !== 0) {
            return null;
        }

        $unpacked = unpack('J*', $bytes);

        if ($unpacked === false) {
            return null;
        }

        $words = [];

        foreach ($unpacked as $word) {
            if (! is_int($word)) {
                return null;
            }

            $words[] = $word;
        }

        try {
            $mask = FreeBusyMask::fromWords($width, $words);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($parts[2] === '') {
            return new NotBookedMask($mask);
        }

        $expiry = DateTimeImmutable::createFromFormat(self::INSTANT, $parts[2], new DateTimeZone('UTC'));

        if ($expiry === false || $expiry->format(self::INSTANT) !== $parts[2]) {
            return null;
        }

        return new NotBookedMask($mask, $expiry);
    }
}
