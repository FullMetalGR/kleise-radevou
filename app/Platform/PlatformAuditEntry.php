<?php

declare(strict_types=1);

namespace App\Platform;

use InvalidArgumentException;

/**
 * One row of the platform audit log before the writer has it: what an operator did and the facts
 * of it. The operator and the tenant are not here; they are columns, handed to PlatformAudit
 * beside the entry, as references.
 *
 * Every payload key is a case of PlatformAuditKey and every value a scalar or a list of scalars,
 * checked here as App\Domain\Audit\AuditEntry checks the tenant trail's, and anything else is
 * refused: a null, a map, a nested list, an object. Nothing is reduced on the way in, since no
 * fact of the vocabulary is a date or an enum; a caller hands the slug, the id or the words.
 */
final readonly class PlatformAuditEntry
{
    /** @var array<string, scalar|list<scalar>> */
    public array $payload;

    /**
     * @param  array<array-key, mixed>  $payload  keyed by PlatformAuditKey values, each value a scalar or a list of scalars. Whatever comes in is checked; this is the boundary that decides what a payload may hold.
     *
     * @throws InvalidArgumentException for a key outside PlatformAuditKey, or a value that is neither a scalar nor a list of scalars
     */
    public function __construct(public PlatformAuditAction $action, array $payload = [])
    {
        $checked = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key) || PlatformAuditKey::tryFrom($key) === null) {
                throw new InvalidArgumentException(sprintf(
                    'The platform audit payload key "%s" is outside the declared list: %s.',
                    (string) $key,
                    implode(', ', array_map(fn (PlatformAuditKey $known): string => $known->value, PlatformAuditKey::cases())),
                ));
            }

            $checked[$key] = self::fact($key, $value);
        }

        $this->payload = $checked;
    }

    /**
     * @return scalar|list<scalar>
     */
    private static function fact(string $key, mixed $value): string|int|float|bool|array
    {
        if (is_scalar($value)) {
            return $value;
        }

        if (is_array($value) && array_is_list($value)) {
            $scalars = [];

            foreach ($value as $member) {
                $scalars[] = is_scalar($member) ? $member : throw self::refused($key, $value);
            }

            return $scalars;
        }

        throw self::refused($key, $value);
    }

    private static function refused(string $key, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The platform audit payload value under "%s" is a %s; a payload holds scalars and lists of scalars.',
            $key,
            is_array($value) ? (array_is_list($value) ? 'list holding something other than scalars' : 'map') : get_debug_type($value),
        ));
    }
}
