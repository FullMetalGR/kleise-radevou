<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One row of the audit trail before anything writes it: what happened, who did it, and the
 * facts of the transition as scalars.
 *
 * The booking is named by its public identifier rather than by the serial id, so nothing above
 * the repository ever holds a database id, and the trail references the booking rather than
 * copying anything out of it.
 *
 * Every payload key is a case of AuditKey and every value is a scalar, or a list of facts per
 * segment, each a map of AuditKey-free field names to scalars: what a move by the business says
 * of each segment whose member changed or whose warning it overrode. Domain values are accepted
 * and reduced here, once, at either depth, so no caller decides how a status or an instant is
 * spelled: an enum becomes its backing value and an instant becomes UTC text with its offset
 * written out, in the one spelling, to microseconds.
 */
final readonly class AuditEntry
{
    /** How an instant is spelled in a payload: UTC, offset written out, microseconds kept. */
    public const string INSTANT = 'Y-m-d\TH:i:s.uP';

    /** @var array<string, scalar|null|list<array<string, scalar|null>>> */
    public array $payload;

    /**
     * @param  string  $bookingUuid  the booking's public identifier
     * @param  array<array-key, mixed>  $payload  keyed by AuditKey values, each value a scalar, a backed enum, an instant, null, or a list of maps from field names to any of those. Whatever comes in is checked; this is the boundary that decides what a payload may hold.
     *
     * @throws InvalidArgumentException for a key outside AuditKey, or a value that is not reducible to a scalar
     */
    public function __construct(
        public string $bookingUuid,
        public AuditAction $action,
        public Actor $actor,
        array $payload = [],
    ) {
        $scalars = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key) || AuditKey::tryFrom($key) === null) {
                throw new InvalidArgumentException(sprintf(
                    'The audit payload key "%s" is outside the declared list: %s.',
                    (string) $key,
                    implode(', ', array_map(fn (AuditKey $known): string => $known->value, AuditKey::cases())),
                ));
            }

            $scalars[$key] = is_array($value) ? self::facts($key, $value) : self::scalar($key, $value);
        }

        $this->payload = $scalars;
    }

    /**
     * A list of facts per segment, each a map of field names to scalars, reduced as one value is.
     *
     * @param  array<array-key, mixed>  $list
     * @return list<array<string, scalar|null>>
     */
    private static function facts(string $key, array $list): array
    {
        if (! array_is_list($list)) {
            throw new InvalidArgumentException(sprintf('The audit payload value under "%s" is an array that is not a list; a payload holds scalars or lists of facts.', $key));
        }

        $facts = [];

        foreach ($list as $fact) {
            if (! is_array($fact) || $fact === [] || array_is_list($fact)) {
                throw new InvalidArgumentException(sprintf('The audit payload list under "%s" holds something other than a map of facts.', $key));
            }

            $reduced = [];

            foreach ($fact as $field => $value) {
                $reduced[(string) $field] = self::scalar($key.'.'.$field, $value);
            }

            $facts[] = $reduced;
        }

        return $facts;
    }

    /**
     * @return scalar|null
     */
    private static function scalar(string $key, mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeImmutable => $value->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT),
            default => throw new InvalidArgumentException(sprintf(
                'The audit payload value under "%s" is a %s; a payload holds scalars.',
                $key,
                get_debug_type($value),
            )),
        };
    }
}
