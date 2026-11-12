<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use RuntimeException;

/**
 * The refusal to delete the implicit staff member, the one row that stands for the business:
 * exactly one row carries the flag, notification templates read it, the opening hours are its
 * pattern, and the feature makes it editable and never removable. The delete of that row is
 * refused whatever names it and whatever the page offered, so a tenant is never left with no
 * implicit member. The sentence a user reads is the controller's, StaffController::IMPLICIT,
 * worded in the request's language.
 */
final class ImplicitStaffKept extends RuntimeException
{
    public const string CODE = 'implicit';

    public function __construct(public readonly int $staffId)
    {
        parent::__construct(sprintf('Staff member %d is the implicit member and is never deleted.', $staffId));
    }
}
