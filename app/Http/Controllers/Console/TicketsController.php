<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Help\DeskTicket;
use App\Help\HelpDesk;
use App\Help\HelpThreadMessage;
use App\Http\ConsoleSession;
use App\Http\Requests\ConsoleTicketReplyRequest;
use App\Http\Requests\ConsoleTicketsRequest;
use App\Platform\ConsoleTime;
use App\Platform\Operator;
use App\Tenancy\TenantUrls;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's help tickets: GET /tickets, GET /tickets/{id}, and POST /tickets/{id}/messages,
 * /close and /reopen, each under operator.
 *
 * The list is every business's tickets in one paged read, twenty-five a page, open before closed
 * and then newest activity first, the order the database keeps; ?page= is the whole of its state,
 * a malformed value reading as the first page and one past the end as the last, which the answer
 * names, and the page is a closure, so paging is a partial reload of the list alone. Each row
 * carries the ticket's subject, its business by name with the business's console page, its status
 * as its value, which the page words, the newest message's instant spelled on the console's clock,
 * the shell naming the zone once, and whether it awaits a reply: open, with the business having
 * written last.
 *
 * A thread is the ticket with the same facts and its messages oldest first, each with its side,
 * its writer's name as written, its instant and its text as stored, line breaks and all; the
 * platform's messages carry the operator's own name, for the platform's records.
 *
 * The three writes are App\Help\HelpDesk's, each with its audit row in its own transaction. A
 * reply leaves the status as it is; Close takes an open ticket and Reopen a closed one, and the
 * same press on a ticket another operator already moved answers the refusal as an error flash
 * rather than failing, so two tabs never see an error page. Each answers a redirect to the thread
 * on the console's host, built from the ticket's id alone and never from the Referer. An id that
 * is not a positive whole number written in digits alone, and one no row holds, answer the
 * console's 404 inside its shell, writing nothing.
 */
final readonly class TicketsController
{
    public const string REPLIED = 'Your reply is sent.';

    public const string CLOSED = 'The ticket is closed.';

    public const string REOPENED = 'The ticket is open again.';

    public const string ALREADY_CLOSED = 'That ticket is already closed.';

    public const string ALREADY_OPEN = 'That ticket is already open.';

    public function __construct(
        private HelpDesk $desk,
        private ConsoleTime $time,
        private ConsoleSession $session,
        private TenantUrls $urls,
        private NotFoundController $notFound,
    ) {}

    public function index(ConsoleTicketsRequest $request): InertiaResponse
    {
        $page = $request->page();

        return Inertia::render('console/Tickets', [
            'tickets' => function () use ($page): array {
                $read = $this->desk->page($page);

                return [
                    'rows' => array_map($this->ticket(...), $read['records']),
                    'total' => $read['total'],
                    'page' => $read['page'],
                    'page_size' => HelpDesk::PAGE_SIZE,
                ];
            },
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $ticketId = self::idOf($id);
        $thread = $ticketId === null ? null : $this->desk->thread($ticketId);

        if ($thread === null) {
            return ($this->notFound)($request);
        }

        return Inertia::render('console/Ticket', [
            'ticket' => $this->ticket($thread->ticket),
            'messages' => array_map($this->message(...), $thread->messages),
        ])->toResponse($request);
    }

    public function reply(ConsoleTicketReplyRequest $request, string $id): Response
    {
        $ticketId = self::idOf($id);

        if ($ticketId === null || ! $this->desk->reply($ticketId, $this->operator($request), $request->reply())) {
            return ($this->notFound)($request);
        }

        $request->session()->flash('flash', ['kind' => 'success', 'message' => self::REPLIED]);

        return redirect($this->thread($ticketId));
    }

    public function close(Request $request, string $id): Response
    {
        return $this->move($request, $id, $this->desk->close(...), self::CLOSED, self::ALREADY_CLOSED);
    }

    public function reopen(Request $request, string $id): Response
    {
        return $this->move($request, $id, $this->desk->reopen(...), self::REOPENED, self::ALREADY_OPEN);
    }

    /**
     * Close or Reopen: the act's own flash where it moved the ticket, the refusal where the ticket
     * already stood where the press would take it, and the 404 where no row holds the id.
     *
     * @param  callable(int, int): bool  $act
     */
    private function move(Request $request, string $id, callable $act, string $moved, string $already): Response
    {
        $ticketId = self::idOf($id);

        if ($ticketId === null) {
            return ($this->notFound)($request);
        }

        if ($act($ticketId, $this->operator($request)->id)) {
            $request->session()->flash('flash', ['kind' => 'success', 'message' => $moved]);
        } elseif ($this->desk->thread($ticketId) === null) {
            return ($this->notFound)($request);
        } else {
            $request->session()->flash('flash', ['kind' => 'error', 'message' => $already]);
        }

        return redirect($this->thread($ticketId));
    }

    private function operator(Request $request): Operator
    {
        return $this->session->operator($request) ?? throw new LogicException('A console write ran without an operator; its route declares no operator.');
    }

    private function thread(int $id): string
    {
        return $this->urls->console('/tickets/'.$id);
    }

    /**
     * The id as a whole number, or null for anything but digits alone with no leading zero. The
     * digits are judged before the number is read, since the reading on its own would take a sign
     * or a space around the digits; it still refuses a number too large to hold.
     */
    private static function idOf(string $id): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
            return null;
        }

        $parsed = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : $parsed;
    }

    /**
     * @return array{id: int, subject: string, tenant: array{name: string, url: string}, status: string, awaiting: bool, last_message: string}
     */
    private function ticket(DeskTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'tenant' => [
                'name' => $ticket->tenantName,
                'url' => $ticket->tenantSlug === null ? '/tenants/closed/'.$ticket->tenantId : '/tenants/'.$ticket->tenantSlug,
            ],
            'status' => $ticket->status->value,
            'awaiting' => $ticket->awaiting,
            'last_message' => $this->time->onScreen($ticket->lastMessageAt),
        ];
    }

    /**
     * @return array{id: int, side: string, name: string, at: string, body: string}
     */
    private function message(HelpThreadMessage $message): array
    {
        return [
            'id' => $message->id,
            'side' => $message->side->value,
            'name' => $message->authorName,
            'at' => $this->time->onScreen($message->at),
            'body' => $message->body,
        ];
    }
}
