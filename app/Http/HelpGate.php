<?php

declare(strict_types=1);

namespace App\Http;

use App\Help\HelpAuthor;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who may use the help tickets of a business: every signed-in user of it, whatever their role and
 * whatever the subscription's state, since a lapsed or closing business can still ask for help. No
 * permission is shared by the three roles, so the routes admit any signed-in principal and this
 * refuses the two that may not write.
 *
 * A support session has no bubble and its help routes answer as a page that does not exist, as
 * its language switch does. The demo account, open to anyone, has the answers and no tickets: its
 * writes answer 403 with the envelope saying so, naming the field the refusal stands beside where
 * the write has one, and its link to a thread answers as one that does not exist.
 */
final readonly class HelpGate
{
    /** The envelope's code for a ticket write on the demo account. */
    public const string DEMO = 'help_demo';

    /** The demo's refusal, a line of the help section. */
    public const string DEMO_DETAIL = 'admin/help.refusals.demo';

    public function __construct(private AdminSession $session, private CurrentTenant $current, private Demo $demo) {}

    /**
     * The signed-in user a ticket write is made as, under their name and role as they are now.
     */
    public function author(Request $request, ?string $field): HelpAuthor
    {
        $principal = $this->session->principal($request);
        $userId = $principal?->ownStaffUserId();
        $role = $principal === null ? null : Role::tryFrom($principal->role);

        if ($principal === null || $userId === null || $role === null) {
            throw new NotFoundHttpException;
        }

        if ($this->demo->is($this->current->get())) {
            throw new HttpResponseException((new ErrorEnvelope(self::DEMO, 403, AdminLines::line(self::DEMO_DETAIL), $field === null ? [] : ['field' => $field]))->toResponse());
        }

        return new HelpAuthor($userId, $principal->name, $role);
    }

    /**
     * The signed-in user a link to a thread opens the admin for: their id, the demo and a support
     * session answered as a page that does not exist.
     */
    public function reader(Request $request): int
    {
        $userId = $this->session->principal($request)?->ownStaffUserId();

        if ($userId === null || $this->demo->is($this->current->get())) {
            throw new NotFoundHttpException;
        }

        return $userId;
    }

    /**
     * The business every read and write is made in: the signed-in session's, never the request's.
     */
    public function tenantId(): int
    {
        return $this->current->get()->id;
    }
}
