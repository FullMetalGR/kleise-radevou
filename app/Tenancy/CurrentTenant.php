<?php

declare(strict_types=1);

namespace App\Tenancy;

use Closure;
use LogicException;

/**
 * The tenant the request resolved, for everything that runs after the middleware. Controllers,
 * gates and use cases take it from here rather than reading the host again, and the row is the
 * one the middleware loaded, so entitlements, subscription state and timezone are property reads.
 *
 * Bound for exactly the span of one request. The middleware binds it before the route runs and
 * releases it after, whatever the route did, so a process serving many requests in a row can
 * never hand one request the tenant of an earlier one. Reading it where nothing is bound, on a
 * central route or outside a request, is a programming error and is answered as one.
 *
 * A request is not the only unit of work a tenant can own. A committed transition is announced
 * to listeners that were never in a request - the sweeper's expiries run in a queued job - and
 * a listener has to know which tenant it is hearing about. The publisher binds the tenant it
 * was built for around the dispatch, through during(), so the answer is the same in both
 * places and no listener has to ask how it got here.
 */
final class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function bind(Tenant $tenant): void
    {
        if ($this->tenant !== null) {
            throw new LogicException(sprintf('A tenant is already bound for this request: "%s".', $this->tenant->slug));
        }

        $this->tenant = $tenant;
    }

    public function release(): void
    {
        $this->tenant = null;
    }

    public function get(): Tenant
    {
        return $this->tenant ?? throw new LogicException('No tenant is bound: this is a central route, or the request has not been resolved.');
    }

    /**
     * Runs the work with this tenant bound, and leaves what was bound before exactly as it was.
     *
     * Where the same tenant is already bound, which is every transition announced inside a
     * request, nothing is bound or released and the work simply runs. Where a different tenant
     * is bound, the caller has paired a tenant with work that is not its own, and that is a
     * programming error rather than a case to absorb: one request serves one tenant and one
     * job is dispatched for one tenant.
     */
    public function during(Tenant $tenant, Closure $work): void
    {
        if ($this->tenant !== null) {
            if ($this->tenant->id !== $tenant->id) {
                throw new LogicException(sprintf(
                    'Tenant "%s" is bound and work for tenant "%s" was asked to run under it.',
                    $this->tenant->slug,
                    $tenant->slug,
                ));
            }

            $work();

            return;
        }

        $this->bind($tenant);

        try {
            $work();
        } finally {
            $this->release();
        }
    }
}
