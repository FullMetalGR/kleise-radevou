<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\SignupHolder;
use App\Http\SignupStrings;
use App\Models\User;
use App\Site\SignupLinks;
use App\Tenancy\InvalidSlug;
use App\Tenancy\Role;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where the signup waits. The row is the only truth, so a person who closes the tab and comes
 * back finds the same page reading the same row. Until the owner's link is clicked, the users
 * row's email_verified_at unset, the page asks the person to check their email; after it, while
 * the row is still provisioning, it says the business is being set up. Either way it polls by
 * partial reload, and once the row is active the person is sent to the subdomain's sign-in page.
 * A slug no signup holds, a reserved one included, is not found. The business and the address
 * the link went to are named only to the browser holding the signup, the one whose cookie names
 * the slug; any other reads the page without them, and polls and is sent on exactly as the
 * holder is, so a guessed slug shows nobody who is signing up. The page answers in its path's
 * language, and its Resend control posts in that language too, the resend itself mailing in the
 * row's.
 */
final readonly class SignupPendingController
{
    public function __construct(private TenantUrls $urls, private SignupLinks $links, private SignupStrings $strings) {}

    public function __invoke(Request $request, string $slug): InertiaResponse|Response
    {
        try {
            $label = Slug::of($slug);
        } catch (InvalidSlug) {
            throw new NotFoundHttpException;
        }

        $tenant = Tenant::query()->where('slug', $label->value)->first();

        if ($tenant?->status === TenantStatus::Active) {
            return Inertia::location($this->urls->signIn($label));
        }

        if ($tenant?->status !== TenantStatus::Provisioning) {
            throw new NotFoundHttpException;
        }

        $holder = SignupHolder::holds($request, $label);
        $owner = User::query()->where('tenant_id', $tenant->id)->where('role', Role::Owner)->first();

        return Inertia::render('signup/Pending', [
            'business' => $holder ? $tenant->name : null,
            'email' => $holder ? $owner?->email : null,
            'verified' => $owner?->email_verified_at !== null,
            'resend' => $this->links->resendPath($label->value, $this->strings->language()),
            'address' => $this->urls->origin($label),
            'status' => $tenant->status->value,
        ]);
    }
}
