<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Billing\PlanCatalogue;
use App\Http\ConsoleFeatures;
use App\Platform\ClosingWindow;
use App\Platform\ConsoleTime;
use App\Platform\SupportSession;
use App\Platform\SupportSessions;
use App\Platform\TenantActivity;
use App\Platform\TenantFacts;
use App\Platform\TenantLookup;
use App\Platform\TenantPerson;
use App\Platform\TenantPlan;
use App\Platform\TenantReadout;
use App\Site\SiteLanguage;
use App\Site\TermsVersion;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tenant's detail, GET /tenants/{slug} under operator: one tenant read whole by TenantReadout
 * inside the console's shell, its route named under console.tenants so the rail lights Tenants.
 * The facts are sent as the page shows them in sections, the account, the plan and the
 * subscription, the people and the activity, each vocabulary value as itself for the page to word
 * and every instant spelled on the console's clock, which the top bar names once for the screen.
 * closing_at carries beside it the instant the retention window ends, thirty days on in elapsed
 * time, read from ClosingWindow::endOf, the window's one home, and the day it ends on the
 * console's clock, ClosingWindow::dayOf's, which a closing row's sentence names as the day it is
 * deleted on. The names the catalogue gives floor and staff, Solo and Team from
 * config/billing.php, ride beside the facts for the page to word those two plans by.
 *
 * The links go out to the hosted page and the admin's sign-in on the tenant's own origin, and to
 * the Audit screen narrowed to the tenant. The page offers Close and Reopen, from the status and
 * the plan sent here: Close on an active or a suspended row, Reopen on a closing row whose plan is
 * free, the shape AccountReopen admits. Change features, is offered on a tenant in the free shape
 * alone, the plan TenantPlan derives as free: the set the row holds and one choice per feature; on
 * every other row the prop is null and its plan is billing's. A slug of the wrong shape, a
 * reserved one, whether or not a row holds it, as the tenant resolver refuses it, and one no row
 * holds answer the console's 404 inside its shell. Nothing here writes.
 *
 * The tenant's open support sessions ride beside the facts: each opened, unended and unexpired by
 * the clock's now, with its operator, the instant it was opened and the instant it ends on the
 * console's clock, and its id for the End beside it.
 *
 * A closed row, which the drop left with no slug, no schema and no users, is read at GET
 * /tenants/closed/{id} by the id it keeps, closed() below, and at no slug: the same page, its
 * central facts and the instant it closed on the console's clock, no links, since the row has no
 * origin left and its audit rows are named by no slug, no features, no support session and no
 * controls. An id naming no closed row answers the console's 404.
 */
final readonly class TenantController
{
    public function __construct(
        private TenantReadout $readout,
        private ConsoleTime $time,
        private TenantUrls $urls,
        private TenantLookup $tenants,
        private SupportSessions $support,
        private PlanCatalogue $catalogue,
        private NotFoundController $notFound,
    ) {}

    public function __invoke(Request $request, string $slug): Response
    {
        $tenant = $this->tenants->bySlug($slug);

        if ($tenant === null) {
            return ($this->notFound)($request);
        }

        $facts = $this->readout->of($tenant);
        $tenantSlug = Slug::of($tenant->slug);

        return Inertia::render('console/Tenant', [
            'tenant' => $this->facts($facts),
            'plan_names' => TenantPlan::names($this->catalogue),
            'links' => [
                'hosted_page' => $this->urls->hostedPage($tenantSlug),
                'sign_in' => $this->urls->signIn($tenantSlug),
                'audit' => '/audit?'.http_build_query(['tenant' => $tenantSlug->value]),
            ],
            'features' => $facts->plan === TenantPlan::Free ? ['current' => $facts->entitlements->toSlugs(), 'offered' => ConsoleFeatures::choices()] : null,
            'support_sessions' => array_map(fn (SupportSession $session): array => [
                'id' => $session->id,
                'operator' => $session->operatorName,
                'opened' => $this->time->onScreen($session->openedAt),
                'ends' => $this->time->onScreen($session->endsAt),
            ], $this->support->openAt($tenant)),
        ])->toResponse($request);
    }

    /**
     * A closed tenant's detail by its id: the central facts alone, nothing offered on it.
     */
    public function closed(Request $request, string $id): Response
    {
        $tenant = $this->tenants->closed($id);

        if ($tenant === null) {
            return ($this->notFound)($request);
        }

        return Inertia::render('console/Tenant', [
            'tenant' => $this->facts($this->readout->of($tenant)),
            'plan_names' => TenantPlan::names($this->catalogue),
            'links' => null,
            'features' => null,
            'support_sessions' => [],
        ])->toResponse($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(TenantFacts $facts): array
    {
        return [
            'slug' => $facts->slug,
            'name' => $facts->name,
            'status' => $facts->status->value,
            'timezone' => $facts->timezone,
            'currency' => $facts->currency,
            'category' => $facts->category?->label(),
            'signed_up' => $this->time->onScreen($facts->signedUpAt),
            'trial_ends' => $this->onScreen($facts->trialEndsAt),
            'grace_ends' => $this->onScreen($facts->graceEndsAt),
            'closing' => $facts->closingAt === null ? null : [
                'at' => $this->time->onScreen($facts->closingAt),
                'retention_ends' => $this->time->onScreen(ClosingWindow::endOf($facts->closingAt)),
                'deleted_on' => ClosingWindow::dayOf($facts->closingAt, $this->time->zone(), SiteLanguage::English),
            ],
            'closed' => $this->onScreen($facts->closedAt),
            'suspension' => $facts->suspension === null ? null : [
                'at' => $this->time->onScreen($facts->suspension->at),
                'reason' => $facts->suspension->reason,
            ],
            'terms' => $facts->terms === null ? null : [
                'at' => $this->time->onScreen($facts->terms->at),
                'version' => TermsVersion::spelled($facts->terms->version, SiteLanguage::English),
            ],
            'state' => $facts->state->value,
            'plan' => $facts->plan?->value,
            'subscription' => $facts->subscription === null ? null : [
                'customer' => $facts->subscription->customerId,
                'status' => $facts->subscription->status,
                'period_end' => $this->onScreen($facts->subscription->currentPeriodEnd),
            ],
            'users' => array_map(fn (TenantPerson $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'role' => $person->role->value,
                'verified' => $person->verified,
            ], $facts->users),
            'activity' => $facts->activity === null ? null : $this->activity($facts->activity),
        ];
    }

    /**
     * @return array{services: int, staff: int, bookings: list<array{status: string, count: int}>, last: string|null}
     */
    private function activity(TenantActivity $activity): array
    {
        $bookings = [];

        foreach ($activity->bookings as $status => $count) {
            $bookings[] = ['status' => $status, 'count' => $count];
        }

        return [
            'services' => $activity->services,
            'staff' => $activity->activeStaff,
            'bookings' => $bookings,
            'last' => $this->onScreen($activity->lastActivityAt),
        ];
    }

    private function onScreen(?DateTimeImmutable $instant): ?string
    {
        return $instant === null ? null : $this->time->onScreen($instant);
    }
}
