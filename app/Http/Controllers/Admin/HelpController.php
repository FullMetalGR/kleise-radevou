<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\EntitlementResolver;
use App\Help\HelpLimit;
use App\Help\HelpLimitReached;
use App\Help\HelpTickets;
use App\Http\AdminNav;
use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\HelpGate;
use App\Http\HelpProps;
use App\Http\Requests\HelpReplyRequest;
use App\Http\Requests\HelpTicketRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The help panel's writes and the link that opens the admin on a thread, for every signed-in user
 * of the business, whatever their role and whatever the subscription's state; HelpGate refuses a
 * support session and the demo account.
 *
 * Each write answers by going back to the page it came from with the thread it was about flashed,
 * so the panel's partial reload of help_thread, sent with the write, reads that thread: a new
 * ticket's, read by its writer; a reply's, read by its writer; and an opened one's, which opening
 * marks read. A ticket the business does not hold, another business's included, answers as a page
 * that does not exist. A write at an hourly limit answers 429 with the envelope saying the limit,
 * beside the field it stands by: the subject for a new ticket, the message for the sixty messages.
 *
 * The link opens the admin on the screen the user lands on after sign-in, through sign-in when
 * needed, with the ticket flashed for the bubble to open the panel on.
 */
final readonly class HelpController
{
    /** The envelope's code at an hourly limit. */
    public const string LIMITED = 'help_limited';

    /** The refusal at each limit, a line of the help section read as a choice by the limit. */
    public const array LIMIT_DETAIL = ['tickets' => 'admin/help.refusals.tickets', 'messages' => 'admin/help.refusals.messages'];

    public function __construct(
        private HelpGate $gate,
        private HelpTickets $tickets,
        private CurrentTenant $current,
        private EntitlementResolver $entitlements,
        private AdminSession $session,
    ) {}

    public function store(HelpTicketRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $uuid = $this->tickets->open($this->gate->tenantId(), $this->gate->author($request, 'subject'), $request->subject(), $request->body());
        } catch (HelpLimitReached $refused) {
            return self::limited($refused->limit, $refused->limit === HelpLimit::Tickets ? 'subject' : 'body');
        }

        return self::back($request, $uuid);
    }

    public function reply(HelpReplyRequest $request, string $uuid): RedirectResponse|JsonResponse
    {
        try {
            $replied = $this->tickets->reply($this->gate->tenantId(), $this->gate->author($request, 'body'), $uuid, $request->body());
        } catch (HelpLimitReached $refused) {
            return self::limited($refused->limit, 'body');
        }

        if (! $replied) {
            throw new NotFoundHttpException;
        }

        return self::back($request, $uuid);
    }

    public function read(Request $request, string $uuid): RedirectResponse
    {
        if (! $this->tickets->markRead($this->gate->tenantId(), $this->gate->author($request, null)->userId, $uuid)) {
            throw new NotFoundHttpException;
        }

        return self::back($request, $uuid);
    }

    public function open(Request $request, string $uuid): RedirectResponse
    {
        $this->gate->reader($request);

        if (! $this->tickets->holds($this->gate->tenantId(), $uuid)) {
            throw new NotFoundHttpException;
        }

        $principal = $this->session->principal($request);
        $home = $principal === null ? AdminSession::PREFIX : AdminNav::home($principal->permissions, $this->entitlements->resolve($this->current->get()));
        $request->session()->flash(HelpProps::OPEN, $uuid);

        return redirect(AdminSession::at($request, $home));
    }

    private static function back(Request $request, string $uuid): RedirectResponse
    {
        $request->session()->flash(HelpProps::THREAD, $uuid);

        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX));
    }

    private static function limited(HelpLimit $limit, string $field): JsonResponse
    {
        return (new ErrorEnvelope(self::LIMITED, 429, trans_choice(self::LIMIT_DETAIL[$limit->value], $limit->most()), ['field' => $field]))->toResponse();
    }
}
