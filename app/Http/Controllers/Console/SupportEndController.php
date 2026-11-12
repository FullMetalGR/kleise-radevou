<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleSession;
use App\Platform\SupportSessions;
use App\Tenancy\TenantUrls;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's End on a support session: POST /support/{id}/end under operator, with no body,
 * offered beside each open session the tenant's detail lists. It answers the redirect back to
 * the detail of the session's tenant, carrying the success flash or, for a session already ended
 * by any of its ends or lapsed at its sixty minutes, the refusal as an error flash, which the
 * detail shows as a toast that stays. An id that is not a positive whole number, and one no row
 * holds, answer the console's 404 inside its shell, writing nothing.
 *
 * What the end writes is App\Platform\SupportSessions's: ended_at and support_ended in the name
 * of the operator who pressed End. The tenant host's next request in that session's browser is
 * the sign-in page, its principal re-reading the row.
 */
final readonly class SupportEndController
{
    public const string ENDED = 'The support session has ended.';

    public const string ALREADY_ENDED = 'This support session has already ended.';

    public function __construct(
        private SupportSessions $sessions,
        private ConsoleSession $session,
        private TenantUrls $urls,
        private NotFoundController $notFound,
    ) {}

    public function __invoke(Request $request, string $id): Response
    {
        $sessionId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $slug = $sessionId === false ? null : $this->sessions->tenantSlugOf($sessionId);

        if ($sessionId === false || $slug === null) {
            return ($this->notFound)($request);
        }

        $operator = $this->session->operator($request) ?? throw new LogicException('A console write ran without an operator; its route declares no operator.');
        $ended = $this->sessions->end($sessionId, $operator->id);

        $request->session()->flash('flash', $ended ? ['kind' => 'success', 'message' => self::ENDED] : ['kind' => 'error', 'message' => self::ALREADY_ENDED]);

        return redirect()->to($this->urls->console('/tenants/'.$slug));
    }
}
