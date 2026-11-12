<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleFeatures;
use App\Http\ConsoleSession;
use App\Http\Requests\NewTenantRequest;
use App\Platform\FreeTenantRefused;
use App\Platform\FreeTenants;
use App\Tenancy\BusinessCategory;
use App\Tenancy\TenantUrls;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The free tenant's form: GET /tenants/new renders the page console/NewTenant inside the console's
 * shell, and POST /tenants builds the tenant, both under operator and both named under
 * console.tenants, so the rail lights Tenants. The form takes what the signup's takes, the kinds
 * of business, the timezones and the central host the slug becomes a subdomain of, and the
 * features as one checkbox per Feature case; no password, since the owner sets their own.
 *
 * A created tenant lands on its detail with a success flash naming the address the owner's link
 * went to, as the detail's own Send password link does. A refusal comes back on its field through
 * the errors bag: the form request's, and the build's refusal of the slug for a row that changed
 * between the check and the build, on the slug's field as the signup does. What the build does is
 * App\Platform\FreeTenants's.
 */
final readonly class NewTenantController
{
    /** The success flash, the owner's address in the blank. */
    public const string CREATED = 'The account is set up, and a set-password link is on its way to %s. It works once, within 72 hours.';

    public function __construct(
        private FreeTenants $tenants,
        private ConsoleSession $session,
        private TenantUrls $urls,
    ) {}

    public function show(): Response
    {
        return Inertia::render('console/NewTenant', [
            'categories' => array_map(fn (BusinessCategory $category): array => ['value' => $category->value, 'label' => $category->label()], BusinessCategory::cases()),
            'timezones' => DateTimeZone::listIdentifiers(),
            'centralHost' => $this->urls->centralHost(),
            'features' => ConsoleFeatures::choices(),
        ]);
    }

    public function store(NewTenantRequest $request): RedirectResponse
    {
        $operator = $this->session->operator($request) ?? throw new LogicException('A console write ran without an operator; its route declares no operator.');
        $details = $request->freeTenant();

        try {
            $tenant = $this->tenants->create($details, $operator);
        } catch (FreeTenantRefused $refused) {
            throw ValidationException::withMessages(['slug' => $refused->getMessage()]);
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => sprintf(self::CREATED, $details->ownerEmail)]);

        return redirect()->to($this->urls->console('/tenants/'.$tenant->slug));
    }
}
