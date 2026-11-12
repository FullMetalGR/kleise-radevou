<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleSession;
use App\Http\Requests\SuspendRequest;
use App\Platform\Operator;
use App\Platform\Suspension;
use App\Platform\SuspensionRefused;
use App\Platform\TenantLookup;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's suspend and resume: POST /tenants/{slug}/suspend with the operator's reason and
 * POST /tenants/{slug}/resume with no body, each under operator. Each answers the redirect back to
 * the tenant's detail, carrying a success flash or the refusal's sentence as an error flash, which
 * the detail shows as a toast that stays until dismissed; a reason the form request refuses comes
 * back on the field instead. The success flash says the owners were told, and for a tenant with no
 * owner user, a row whose owners have all gone, says nobody was, the write having mailed nobody; a
 * row still provisioning never reaches either, since a suspend refuses every row that is not active
 * and a resume every row that is not suspended. The slug is read as the detail reads it, and a slug
 * naming no tenant answers the console's 404 inside its shell, writing nothing.
 *
 * What either write does is App\Platform\Suspension's: the row, the audit row and the owners' mail.
 */
final readonly class SuspensionController
{
    public const string SUSPENDED = 'The account is suspended, and its owners have been sent the reason.';

    public const string RESUMED = 'The account is active again, and its owners have been told.';

    /**
     * The suspension's flash for a tenant with no owner user, who is mailed nothing.
     */
    public const string SUSPENDED_NO_OWNER = 'The account is suspended. It has no owner, so the reason was sent to nobody.';

    /**
     * The resumption's flash for a tenant with no owner user, who is mailed nothing.
     */
    public const string RESUMED_NO_OWNER = 'The account is active again. It has no owner, so nobody was told.';

    public function __construct(
        private Suspension $suspension,
        private TenantLookup $tenants,
        private ConsoleSession $session,
        private TenantUrls $urls,
        private NotFoundController $notFound,
    ) {}

    public function suspend(SuspendRequest $request, string $slug): Response
    {
        return $this->answer($request, $slug, [self::SUSPENDED_NO_OWNER, self::SUSPENDED], fn (Tenant $tenant, Operator $operator): int => $this->suspension->suspend($tenant, $operator, $request->reason()));
    }

    public function resume(Request $request, string $slug): Response
    {
        return $this->answer($request, $slug, [self::RESUMED_NO_OWNER, self::RESUMED], fn (Tenant $tenant, Operator $operator): int => $this->suspension->resume($tenant, $operator));
    }

    /**
     * Runs the write on the tenant the slug names and answers the redirect to its detail with the
     * flash, the first success sentence where the write mailed no owner and the second where it
     * mailed one or more, or the console's 404 where the slug names none.
     *
     * @param  array{0: string, 1: string}  $success
     * @param  Closure(Tenant, Operator): int  $write  answering how many owners it mailed
     */
    private function answer(Request $request, string $slug, array $success, Closure $write): Response
    {
        $tenant = $this->tenants->bySlug($slug);

        if ($tenant === null) {
            return ($this->notFound)($request);
        }

        $operator = $this->session->operator($request) ?? throw new LogicException('A console write ran without an operator; its route declares no operator.');

        try {
            $flash = ['kind' => 'success', 'message' => $write($tenant, $operator) === 0 ? $success[0] : $success[1]];
        } catch (SuspensionRefused $refused) {
            $flash = ['kind' => 'error', 'message' => $refused->getMessage()];
        }

        $request->session()->flash('flash', $flash);

        return redirect()->to($this->urls->console('/tenants/'.$tenant->slug));
    }
}
