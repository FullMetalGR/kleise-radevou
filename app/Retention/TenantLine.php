<?php

declare(strict_types=1);

namespace App\Retention;

use App\Tenancy\Tenant;

/**
 * One tenant line of the retention table, as the sweep runs it: one statement over one tenant's
 * schema, in a transaction of its own, on the application's clock, deleting or nulling exactly the
 * rows of that schema past the line's period and nothing else. retention:sweep runs every tenant
 * line over every built schema in turn, after the central lines. A line is idempotent, as a
 * central line is: a second run in the same instant finds nothing.
 *
 * What a line answers is the count alone, and its table is the one other thing of it the sweep
 * logs, beside the tenant whose schema it ran in: nothing of a row ever leaves the statement.
 */
interface TenantLine
{
    /** The table the line's statement writes, spelled as the schema names it, unqualified. */
    public function table(): string;

    /** Runs the line's statement over the tenant's schema in its own transaction and answers how many rows it moved. */
    public function apply(Tenant $tenant): int;
}
