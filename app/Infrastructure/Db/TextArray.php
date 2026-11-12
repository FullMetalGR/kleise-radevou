<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A PostgreSQL text[] column as a list of strings. The literal grammar lives here and nowhere
 * else. Elements are always quoted on the way out; on the way in a null element is dropped,
 * because every column cast this way holds slugs and a null is not one.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class TextArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Column {$key} did not arrive as a PostgreSQL array literal.");
        }

        return self::decode($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Column {$key} takes a list of strings.");
        }

        return self::encode($value);
    }

    /**
     * @return list<string>
     */
    public static function decode(string $literal): array
    {
        if (! str_starts_with($literal, '{') || ! str_ends_with($literal, '}')) {
            throw new InvalidArgumentException("Not a PostgreSQL array literal: {$literal}");
        }

        $body = substr($literal, 1, -1);
        $length = strlen($body);
        $values = [];
        $position = 0;

        while ($position < $length) {
            if ($body[$position] === '"') {
                $values[] = self::quotedElement($body, $position);
            } else {
                $end = strpos($body, ',', $position);
                $end = $end === false ? $length : $end;
                $element = substr($body, $position, $end - $position);
                $position = $end;

                if ($element !== 'NULL') {
                    $values[] = $element;
                }
            }

            if ($position < $length && $body[$position] === ',') {
                $position++;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $values
     */
    public static function encode(array $values): string
    {
        $quoted = array_map(
            static fn (string $value): string => '"'.addcslashes($value, '"\\').'"',
            $values,
        );

        return '{'.implode(',', $quoted).'}';
    }

    /**
     * Reads one double-quoted element starting at the opening quote and leaves the position just
     * past the closing one.
     */
    private static function quotedElement(string $body, int &$position): string
    {
        $length = strlen($body);
        $element = '';
        $position++;

        while (true) {
            if ($position >= $length) {
                throw new InvalidArgumentException("Unterminated element in PostgreSQL array literal: {{$body}}");
            }

            $char = $body[$position];

            if ($char === '"') {
                $position++;

                return $element;
            }

            if ($char === '\\') {
                $position++;

                if ($position >= $length) {
                    throw new InvalidArgumentException("Unterminated element in PostgreSQL array literal: {{$body}}");
                }

                $char = $body[$position];
            }

            $element .= $char;
            $position++;
        }
    }
}
