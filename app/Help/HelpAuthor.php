<?php

declare(strict_types=1);

namespace App\Help;

use App\Tenancy\Role;

/**
 * A user of the business writing in a help thread: their id, the name they write under, which the
 * message keeps as written, and their role at the write, which the mail to the platform names.
 */
final readonly class HelpAuthor
{
    public function __construct(
        public int $userId,
        public string $name,
        public Role $role,
    ) {}
}
