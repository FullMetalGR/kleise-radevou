<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleSession;
use App\Platform\PasswordLinks;
use App\Platform\TenantLookup;
use App\Tenancy\TenantUrls;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's set-password link: POST /tenants/{slug}/users/{id}/password-link under operator,
 * with no body, offered beside every user of the tenant on its detail. It answers the redirect back
 * to the tenant's detail with a success flash naming the address the link went to, which the detail
 * shows as a toast. The slug is read as the detail reads it, and a slug naming no tenant, an id
 * that is not a positive whole number written in digits alone, and an id no user of this tenant
 * holds, another tenant's user among them, answer the console's 404 inside its shell, writing
 * nothing. The digits are the parse's to judge and not the route's: a constraint on the route would
 * leave a malformed id to the tenant group's fall-through, a 405 off the console's GET catch-all,
 * rather than the console's 404.
 *
 * What the send does is App\Platform\PasswordLinks's: the link, the audit row and the mail. The
 * clear token never reaches this class.
 */
final readonly class PasswordLinkController
{
    /** The success flash, the user's address in the blank. */
    public const string SENT = 'A set-password link is on its way to %s. It works once, within 72 hours.';

    public function __construct(
        private PasswordLinks $links,
        private TenantLookup $tenants,
        private ConsoleSession $session,
        private TenantUrls $urls,
        private NotFoundController $notFound,
    ) {}

    public function __invoke(Request $request, string $slug, string $id): Response
    {
        $tenant = $this->tenants->bySlug($slug);
        $userId = preg_match('/\A[0-9]+\z/', $id) === 1 ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if ($tenant === null || $userId === false) {
            return ($this->notFound)($request);
        }

        $operator = $this->session->operator($request) ?? throw new LogicException('A console write ran without an operator; its route declares no operator.');
        $user = $this->links->send($tenant, $operator, $userId);

        if ($user === null) {
            return ($this->notFound)($request);
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => sprintf(self::SENT, $user->email)]);

        return redirect()->to($this->urls->console('/tenants/'.$tenant->slug));
    }
}
