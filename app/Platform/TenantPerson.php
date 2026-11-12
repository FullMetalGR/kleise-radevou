<?php

declare(strict_types=1);

namespace App\Platform;

use App\Tenancy\Role;

/**
 * One users row of a tenant, as the console's detail lists it: the id, which the set-password
 * link beside the user names, the name, the email, the role and whether the email was verified.
 * Nothing else of the row is read: the password, the remember token, the verification hash and
 * the set-password link's hash never leave the table.
 */
final readonly class TenantPerson
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public Role $role,
        public bool $verified,
    ) {}
}
