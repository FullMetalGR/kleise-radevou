<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\User;

/**
 * A trial as the verification click's build left it: the tenant row and its owner, re-read after
 * the build's commit, for the welcome email the click sends. Read after the commit rather than
 * inside it so the mail names the row as it stands, the trial's end among it, and nothing the
 * mail reads can roll back.
 */
final readonly class BuiltTrial
{
    public function __construct(
        public Tenant $tenant,
        public User $owner,
    ) {}
}
