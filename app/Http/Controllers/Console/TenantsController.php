<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Billing\PlanCatalogue;
use App\Http\Requests\ConsoleTenantsRequest;
use App\Platform\ConsoleTime;
use App\Platform\ListedTenant;
use App\Platform\TenantList;
use App\Platform\TenantPlan;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The tenant list, GET / under operator and the console's home, where the door lands an operator:
 * every tenant newest signup first, twenty-five a page, inside the console's shell. Each row
 * carries the slug, null on a closed row whose slug the drop released, and the path of the
 * tenant's detail, which the page links the slug to, by the slug or, on a closed row, by its id
 * (ListedTenant::detailPath()), the name, the status, the plan TenantPlan derives, the
 * subscription state, each as its vocabulary value for the page to word, and the signup instant
 * spelled on the console's clock; the top bar names that clock once for the screen from the shared
 * prop. Beside them ride the names the catalogue gives floor and staff, Solo and Team from
 * config/billing.php, which the page words those two plans by in the column and the filter.
 *
 * The filters are the request's own as it read them, every unset or malformed one the empty
 * string the filter bar holds for it, read eager because they cost nothing; the page is a closure,
 * so a change of the filters or the page is a partial reload of the two alone.
 */
final readonly class TenantsController
{
    public function __construct(private TenantList $list, private ConsoleTime $time, private PlanCatalogue $catalogue) {}

    public function __invoke(ConsoleTenantsRequest $request): InertiaResponse
    {
        $query = $request->filter();

        return Inertia::render('console/Tenants', [
            'filters' => [
                'search' => $query->search ?? '',
                'status' => $query->status->value ?? '',
                'state' => $query->state->value ?? '',
                'plan' => $query->plan->value ?? '',
                'from' => $query->from ?? '',
                'to' => $query->to ?? '',
            ],
            'plan_names' => TenantPlan::names($this->catalogue),
            'tenants' => function () use ($query): array {
                $read = $this->list->page($query);

                return [
                    'rows' => array_map($this->row(...), $read['records']),
                    'total' => $read['total'],
                    'page' => $query->page,
                    'page_size' => TenantList::PAGE_SIZE,
                ];
            },
        ]);
    }

    /**
     * @return array{slug: string|null, detail: string, name: string, status: string, plan: string|null, state: string, signed_up: string}
     */
    private function row(ListedTenant $tenant): array
    {
        return [
            'slug' => $tenant->slug,
            'detail' => $tenant->detailPath(),
            'name' => $tenant->name,
            'status' => $tenant->status->value,
            'plan' => $tenant->plan?->value,
            'state' => $tenant->state->value,
            'signed_up' => $this->time->onScreen($tenant->signedUpAt),
        ];
    }
}
