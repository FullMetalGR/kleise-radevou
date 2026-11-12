<?php

declare(strict_types=1);

namespace App\Retention;

use RuntimeException;

/**
 * No booking of the tenant carries the address an erasure or an export names: none was ever made
 * under it, or an erasure already took it off every booking. Answered as the framework's 404, as
 * a customer's page answers an address no booking carries. The message names the tenant and
 * never the address.
 */
final class CustomerUnknown extends RuntimeException
{
    public function __construct(string $tenant)
    {
        parent::__construct(sprintf('No booking of tenant "%s" carries the address named.', $tenant));
    }
}
