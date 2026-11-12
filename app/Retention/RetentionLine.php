<?php

declare(strict_types=1);

namespace App\Retention;

/**
 * One line of the retention table, as the sweep runs it: one statement in a transaction of its
 * own, on the application's clock, deleting or nulling exactly the rows past the line's period
 * and nothing else. A line is idempotent, so a second run in the same instant finds nothing, and
 * a run the day after a failed one starts again from the first line.
 *
 * What a line answers is the count alone, and its table is the one other thing of it the sweep
 * logs: nothing of a row ever leaves the statement.
 */
interface RetentionLine
{
    /** The table the line's statement writes, spelled as the schema names it. */
    public function table(): string;

    /** Runs the line's statement in its own transaction and answers how many rows it moved. */
    public function apply(): int;
}
