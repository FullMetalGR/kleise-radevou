<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleSession;
use App\Http\Requests\CloseRequest;
use App\Notifications\AccountClosing;
use App\Platform\AccountClose;
use App\Platform\AccountReopen;
use App\Platform\CloseRefused;
use App\Platform\Operator;
use App\Platform\OperatorClose;
use App\Platform\ReopenRefused;
use App\Platform\TenantLookup;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's close and reopen: POST /tenants/{slug}/close with the operator's reason and POST
 * /tenants/{slug}/reopen with no body, each under operator. Each answers the redirect back to the
 * tenant's detail, carrying a success flash or the refusal's sentence as an error flash, which the
 * detail shows as a toast that stays until dismissed; a reason the form request refuses comes back
 * on the field instead, as the suspension's does. The success flash says the owners were told, and
 * for a tenant with no owner user, a row whose owners have all gone, says nobody was, the write
 * having mailed nobody. The slug is read as the detail reads it, and a slug naming no tenant
 * answers the console's 404 inside its shell, writing nothing; a closed row has no slug, so
 * neither route reaches it.
 *
 * What the close does is App\Platform\AccountClose's, the one writer the owner's close shares, for
 * the operator's closer, OperatorClose: the row, the audit row, the cancel and the owners' mail.
 * What the reopen does is App\Platform\AccountReopen's.
 */
final readonly class ClosingController
{
    public const string CLOSED = 'The account is closing, and its owners have been sent the reason and the day it closes.';

    public const string REOPENED = 'The account stays open, and its owners have been told.';

    /** The close's flash for a tenant with no owner user, who is mailed nothing. */
    public const string CLOSED_NO_OWNER = 'The account is closing. It has no owner, so the reason was sent to nobody.';

    /** The reopen's flash for a tenant with no owner user, who is mailed nothing. */
    public const string REOPENED_NO_OWNER = 'The account stays open. It has no owner, so nobody was told.';

    public function __construct(
        private AccountClose $close,
        private AccountClosing $closing,
        private AccountReopen $reopen,
        private TenantLookup $tenants,
        private ConsoleSession $session,
        private TenantUrls $urls,
        private NotFoundController $notFound,
    ) {}

    public function close(CloseRequest $request, string $slug): Response
    {
        return $this->answer($request, $slug, [self::CLOSED_NO_OWNER, self::CLOSED], function (Tenant $tenant, Operator $operator) use ($request): int {
            $closer = new OperatorClose($this->closing, $operator, $request->reason());
            $this->close->close($tenant, $closer);

            return $closer->told();
        });
    }

    public function reopen(Request $request, string $slug): Response
    {
        return $this->answer($request, $slug, [self::REOPENED_NO_OWNER, self::REOPENED], fn (Tenant $tenant, Operator $operator): int => $this->reopen->reopen($tenant, $operator));
    }

    /**
     * Runs the write on the tenant the slug names and answers the redirect to its detail with the
     * flash, the first success sentence where the write mailed no owner and the second where it
     * mailed one or more, the refusal's sentence, which is each refusal's message, or the
     * console's 404 where the slug names none.
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
        } catch (CloseRefused $refused) {
            $flash = ['kind' => 'error', 'message' => $refused->sentence(SiteLanguage::English)];
        } catch (ReopenRefused $refused) {
            $flash = ['kind' => 'error', 'message' => $refused->getMessage()];
        }

        $request->session()->flash('flash', $flash);

        return redirect()->to($this->urls->console('/tenants/'.$tenant->slug));
    }
}
