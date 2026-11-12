<?php

declare(strict_types=1);

namespace App\Platform;

use App\Tenancy\Tenant;
use stdClass;
use UnexpectedValueException;

/**
 * How the console's readers of the central tables read a row the database handed back, in one
 * home: a text column, a text column that may be null, the refusal of a column in a shape the
 * reader cannot read, naming the reader and the column, and the refusal of a tenant row gone
 * from under a reader that holds the tenant. Each refusal is a programming or schema error,
 * never an answer to an operator, so it raises.
 *
 * The reader is named in words, as its refusal starts: "The console's detail", "The suspension".
 */
final class CentralRow
{
    private function __construct() {}

    /**
     * @throws UnexpectedValueException where the column is absent or not a string
     */
    public static function text(stdClass $row, string $column, string $reader): string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : throw self::misshapen($column, $reader);
    }

    /**
     * @throws UnexpectedValueException where the column is neither null nor a string
     */
    public static function optionalText(stdClass $row, string $column, string $reader): ?string
    {
        $value = $row->{$column} ?? null;

        return $value === null || is_string($value) ? $value : throw self::misshapen($column, $reader);
    }

    public static function misshapen(string $column, string $reader): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('%s read a "%s" it cannot read.', $reader, $column));
    }

    /**
     * The tenant row a reader holds the tenant of is gone: no row holds its id.
     */
    public static function gone(Tenant $tenant): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('No tenant row holds the id %d.', $tenant->id));
    }
}
