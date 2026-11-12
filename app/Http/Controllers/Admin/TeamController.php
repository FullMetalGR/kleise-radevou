<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Http\AdminStrings;
use App\Http\ErrorEnvelope;
use App\Http\Requests\StaffRequest;
use App\Http\Requests\TeamInviteRequest;
use App\Http\Requests\TeamRoleRequest;
use App\Platform\PasswordLinks;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use App\Tenancy\Team;
use App\Tenancy\TeamRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Team screen, the owner's alone: every route declares manageBilling, the one permission the
 * owner holds and nobody else does, support included, which acts as nobody's user and so invites
 * nobody. No route is gated by the subscription: who may sign in is no configuration, and a lapsed
 * owner may need to bring in the person who fixes the card.
 *
 * index renders the page Team with every member (App\Tenancy\Team::members); store invites, update
 * changes a role, destroy removes a member and link mails the set-password link again, each
 * answering the screen back with a flash naming who it touched, a user of another tenant or of
 * none answering not-found. A refusal of the screen's guards is a 409 envelope in the request's
 * language with the code the screen branches on nowhere; an owner who stepped down from owner
 * lands on Home, the Team screen being no longer theirs. The words are the team section, named
 * through AdminStrings::need.
 */
final readonly class TeamController
{
    /** The section of lang/{locale}/admin the page reads its words from. */
    public const string SECTION = 'team';

    /** The writes' flashes, keys of team.php worded in the request's language. */
    public const string INVITED = 'admin/team.flashes.invited';

    public const string ROLE_SAVED = 'admin/team.flashes.role_saved';

    public const string LINK_SENT = 'admin/team.flashes.link_sent';

    public const string REMOVED = 'admin/team.flashes.removed';

    public function __construct(
        private CurrentTenant $current,
        private Team $team,
        private AdminSession $session,
    ) {}

    public function index(Request $request): Response
    {
        AdminStrings::need($request, self::SECTION);
        $tenant = $this->current->get();
        $viewer = $this->viewer($request);

        return Inertia::render('Team', [
            'members' => fn (): array => $this->team->members($tenant, $viewer),
        ]);
    }

    public function store(TeamInviteRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $user = $this->team->invite($this->current->get(), $request->name(), $request->email(), $request->role());
        } catch (TeamRefused $refused) {
            return self::refusal($refused);
        }

        return $this->flashed($request, self::linkSentence(self::INVITED, $user->email));
    }

    public function update(TeamRoleRequest $request, int $id): RedirectResponse|JsonResponse
    {
        try {
            $user = $this->team->changeRole($this->current->get(), $id, $request->role()) ?? throw new NotFoundHttpException;
        } catch (TeamRefused $refused) {
            return self::refusal($refused);
        }

        if ($user->id === $this->viewer($request) && $user->role !== Role::Owner) {
            $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(self::ROLE_SAVED, ['name' => $user->name])]);

            return redirect()->to(AdminSession::at($request, AdminSession::PREFIX));
        }

        return $this->back($request, self::ROLE_SAVED, ['name' => $user->name]);
    }

    public function destroy(Request $request, int $id): RedirectResponse|JsonResponse
    {
        try {
            $user = $this->team->remove($this->current->get(), $id, $this->viewer($request)) ?? throw new NotFoundHttpException;
        } catch (TeamRefused $refused) {
            return self::refusal($refused);
        }

        return $this->back($request, self::REMOVED, ['name' => $user->name]);
    }

    public function link(Request $request, int $id): RedirectResponse
    {
        $user = $this->team->sendLink($this->current->get(), $id) ?? throw new NotFoundHttpException;

        return $this->flashed($request, self::linkSentence(self::LINK_SENT, $user->email));
    }

    /** The signed-in user's id, null for support. */
    private function viewer(Request $request): ?int
    {
        return $this->session->principal($request)?->ownStaffUserId();
    }

    /**
     * Back to the screen with a success flash, the key worded in the request's language.
     *
     * @param  array<string, string>  $values
     */
    private function back(Request $request, string $key, array $values): RedirectResponse
    {
        return $this->flashed($request, StaffRequest::line($key, $values));
    }

    private function flashed(Request $request, string $message): RedirectResponse
    {
        $request->session()->flash('flash', ['kind' => 'success', 'message' => $message]);

        return redirect()->to(AdminSession::at($request, AdminSession::PREFIX.'/team'));
    }

    /**
     * A flash naming the set-password link sent to an address and how long it works, read as a
     * choice by the link's hours from PasswordLinks::LIFETIME, so a changed lifetime needs no
     * copy edit.
     */
    private static function linkSentence(string $key, string $email): string
    {
        return trans_choice($key, PasswordLinks::hours(), ['email' => $email]);
    }

    private static function refusal(TeamRefused $refused): JsonResponse
    {
        return (new ErrorEnvelope($refused->refusal, 409, StaffRequest::line($refused->key())))->toResponse();
    }
}
