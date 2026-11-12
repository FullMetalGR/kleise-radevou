<?php

declare(strict_types=1);

namespace App\Platform;

use RuntimeException;

/**
 * The free tenant's slug refused by the build: a row of that slug the provisioner found
 * suspended, closing or held by a signup, or any row this create did not make, active or another
 * create's build in flight, or one another run inserted first, which the form's own check missed
 * because the row changed between the check and the build. The message is the sentence the form
 * shows on the slug's field; nothing was built, recorded or sent.
 */
final class FreeTenantRefused extends RuntimeException
{
    public static function because(string $sentence): self
    {
        return new self($sentence);
    }
}
