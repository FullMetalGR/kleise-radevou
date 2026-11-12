<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The top bar's language control: the signed-in user's own language, written as users.locale for
 * them alone, so two users of one tenant read the admin in two languages. The language is the
 * path's last segment, one of the two by the route's constraint, so a body is never read and no
 * other value reaches here.
 *
 * A support session's write is refused 404, as the control is not rendered for it: it has no user
 * row to write. It is a person's
 * preference and no configuration of the business, so it carries no subscription gate and is
 * written on any plan and in any state. The answer is a redirect back to the page the control was
 * pressed on, with no flash: the next page is in the chosen language, which says what was done.
 */
final readonly class LanguageController
{
    public function __construct(
        private AdminSession $session,
        private CurrentTenant $current,
    ) {}

    public function __invoke(Request $request, string $language): RedirectResponse
    {
        $userId = $this->session->principal($request)?->ownStaffUserId();

        if ($userId === null) {
            throw new NotFoundHttpException;
        }

        User::query()->whereKey($userId)->where('tenant_id', $this->current->get()->id)->update(['locale' => SiteLanguage::from($language)->value]);

        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX));
    }
}
