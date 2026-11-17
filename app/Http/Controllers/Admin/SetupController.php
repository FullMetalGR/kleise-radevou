<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\EntitlementResolver;
use App\Billing\Feature;
use App\Http\AdminSession;
use App\Infrastructure\Db\SetupRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The setup wizard's own writes, each body-less and each only recording a fact of the business:
 * that the wizard came up by itself, that Team or Online payments was skipped, and that Finish
 * was pressed on the Booking link step. Under the owner's permission and never the subscription
 * gate, since none of them configures anything. Each answers with a redirect back, Home where the
 * request names no page, so Home's props arrive re-read and the step facts with them.
 *
 * A skip is taken only for a step that applies to the business: Team on an account without the
 * staff feature is not one of its steps, so its skip is not found and nothing is recorded, or the
 * step would read as skipped, unseen, the day the account gained the feature.
 */
final readonly class SetupController
{
    public function __construct(private CurrentTenant $current, private SetupRecords $records, private EntitlementResolver $entitlements) {}

    public function opened(Request $request): RedirectResponse
    {
        $this->records->markOpened($this->current->get());

        return $this->back($request);
    }

    public function skip(Request $request, string $step): RedirectResponse
    {
        $tenant = $this->current->get();

        if (! $this->records->progress($tenant, $this->entitlements->resolve($tenant)->allows(Feature::Staff))->applies($step)) {
            throw new NotFoundHttpException;
        }

        $this->records->markSkipped($tenant, $step);

        return $this->back($request);
    }

    public function finish(Request $request): RedirectResponse
    {
        $this->records->markLinkFinished($this->current->get());

        return $this->back($request);
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX));
    }
}
