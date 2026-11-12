<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use RuntimeException;
use Throwable;

/**
 * The staff delete guard's refusal: a member some booking item names, in any status, is not
 * deleted, because the items are history and a row deleted from under them would leave them
 * naming nobody. The way forward is to deactivate the member, which the admin offers beside the
 * button that earned this and then leaves for the list.
 *
 * The refusal is the foreign key's, migration 000013: the delete fails with SQLSTATE 23503 and
 * StaffRecords reads that code and nothing else. The sentence a user reads is the controller's,
 * StaffController::REFERENCED, worded in the request's language.
 */
final class StaffReferenced extends RuntimeException
{
    public const string CODE = 'referenced';

    /** foreign_key_violation: the key refused the delete because an item names the row. */
    public const string SQLSTATE = '23503';

    public function __construct(public readonly int $staffId, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Staff member %d is named by booking items and cannot be deleted.', $staffId), 0, $previous);
    }
}
