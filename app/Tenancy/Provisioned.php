<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * The outcome of a provisioning run: the tenant, whether this run created its row, and whether it
 * found the row already active and changed nothing. A run that neither created the row nor found
 * it active finished a row a failed run had left provisioning, tenant:provision's retry.
 *
 * The tenant is active whenever the run built it or found it so. The one exception is a run told
 * to finish only a row it creates, the console's free tenant: a provisioning row it finds is
 * another create's build in flight, handed back as it was found, unbuilt, with created false for
 * the caller to refuse.
 */
final readonly class Provisioned
{
    public function __construct(
        public Tenant $tenant,
        public bool $created,
        public bool $alreadyActive,
    ) {}
}
