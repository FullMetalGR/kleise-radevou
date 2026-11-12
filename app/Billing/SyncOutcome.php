<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * What the subscription sync did with what it was handed, so the webhook that called it can
 * record the event truthfully: applied to a tenant, ignored because no tenant answers to the
 * customer, refused because the snapshot could not be resolved, or refused because the row's
 * revision moved from the one the caller read it at, nothing written and the caller to read again.
 */
enum SyncOutcome: string
{
    case Applied = 'applied';
    case UnknownTenant = 'unknown_tenant';
    case Unresolved = 'unresolved';
    case Stale = 'stale';
}
