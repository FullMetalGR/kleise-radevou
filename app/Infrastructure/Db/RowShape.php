<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Tenancy\Tenant;
use LogicException;
use stdClass;

/**
 * The guard the admin's readers put over a row a statement handed back, and the sentence its
 * refusal says. A reader names its rows and its tenant once and reads each column through this,
 * so a guard is one call per column rather than a block of conditions, and every refusal names
 * the rows and the tenant and never a value, which may be a customer's. BookingRecords,
 * CustomerRecords, ReportFigures and CalendarRoster read through it.
 *
 * The readers written before it guard inline, most as a block of conditions over a row;
 * BookingReader, CatalogReader, PostgresCheckoutRecords, PostgresServiceRepository,
 * PostgresBusinessDirectory and BookingNotes spell this sentence, the rest their own, some
 * naming no tenant, the booking writer and the sweep batch an UnexpectedValueException. A reader
 * moving onto this one takes this sentence, as CalendarRoster's did.
 *
 * A row the schema shapes otherwise is a bug in the statement or a schema the code was not
 * written against, never input to answer politely, so the refusal is a LogicException.
 */
final readonly class RowShape
{
    public function __construct(private string $rows, private string $tenant) {}

    public static function of(string $rows, Tenant $tenant): self
    {
        return new self($rows, $tenant->slug);
    }

    public function row(mixed $row): stdClass
    {
        return $row instanceof stdClass ? $row : throw $this->misshapen();
    }

    public function int(mixed $value): int
    {
        return is_int($value) ? $value : throw $this->misshapen();
    }

    public function string(mixed $value): string
    {
        return is_string($value) ? $value : throw $this->misshapen();
    }

    public function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : throw $this->misshapen();
    }

    public function misshapen(): LogicException
    {
        return new LogicException(sprintf('A %s row of tenant "%s" is not shaped as the schema declares.', $this->rows, $this->tenant));
    }
}
