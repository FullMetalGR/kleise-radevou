<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\ApiLines;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * A start as this API spells one, validated the same way wherever it arrives: RFC 3339 with the
 * date, the time and the offset all written out, which is what the availability answer hands the
 * client and what the client hands back.
 *
 * The fields are read out of the text and checked to be a real date and a real time before any
 * constructor sees them, because a constructor handed the 30th of February answers with the 2nd
 * of March rather than a refusal. The offset covers the real range, so a start can never arrive
 * with one no zone has.
 *
 * Both routes that take a start share this. A hold and a move name the same kind of instant, and
 * a second copy of the pattern would be a second answer to what a valid start looks like: the
 * one that mattered would be whichever route the customer happened to be on.
 */
final readonly class Instant
{
    private const string RFC3339 = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:[Zz]|[+-](?:0\d|1[0-4]):[0-5]\d)$/';

    /** The refusal's sentence, its key of api.php. */
    public const string SENTENCE = 'api.validation.start';

    /**
     * The rule itself, as a closure a form request lists beside its others.
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! self::isInstant($value)) {
                $fail(ApiLines::line(self::SENTENCE));
            }
        };
    }

    /**
     * A validated start as an instant. It is built from text the rule has already read character
     * by character, so no date rolls over into the month after it; the zone is named although the
     * text carries an offset, so a parse can never fall back to the process default.
     *
     * Named read and not of, because ::of in this codebase is a named constructor and answers
     * the class it is on: Currency::of, Slug::of, Actor::of, LockKeySet::of. This answers a
     * DateTimeImmutable, so it borrows the verb the readers use for turning stored or wire text
     * back into an instant rather than the one that would promise a value of its own type.
     */
    public static function read(mixed $value): DateTimeImmutable
    {
        if (! is_string($value)) {
            throw new LogicException('The start was validated and is a string.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function isInstant(mixed $value): bool
    {
        return is_string($value)
            && preg_match(self::RFC3339, $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && (int) $parts[4] <= 23
            && (int) $parts[5] <= 59
            && (int) $parts[6] <= 59;
    }
}
