<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\ConsoleSession;
use App\Http\Requests\ConsoleMessagesRequest;
use App\Platform\ConsoleTime;
use App\Platform\ContactInbox;
use App\Platform\InboxView;
use App\Platform\ReceivedMessage;
use App\Tenancy\TenantUrls;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's contact messages: GET /messages and POST /messages/{id}/archive, each under
 * operator.
 *
 * The screen lists one view of the contact form's messages newest first, twenty-five a page,
 * inside the console's shell: ?view= open, the default, or archived, and ?page=, a malformed value
 * of either reading as unset. Each message carries its received instant spelled on the console's
 * clock, the top bar naming the zone once for the screen, the sender's name and address, the
 * language as its value, which the page words, and the message as it was stored, line breaks and
 * all. The view is the request's own, read eager because it costs nothing, and the page a closure,
 * so a change of either is a partial reload of the list alone.
 *
 * Archive takes no body; the press carries the screen's view and page in its own query string,
 * read as tolerantly as the screen reads them. What it writes is App\Platform\ContactInbox's:
 * archived_at and contact_archived in one transaction. It answers a redirect to the screen on the
 * console's host, built from that view and that page alone and never from the Referer, so no
 * address another host wrote is followed: the view kept, and the page kept while it still holds a
 * message, else the last page that does, so an archive that empties the last page lands on a
 * page with rows. The redirect carries the success flash or, for a message already archived, the
 * refusal as an error flash, which the screen shows as a toast that stays. An id that is not a
 * positive whole number, and one no row holds, answer the console's 404 inside its shell, writing
 * nothing. There is no reply from the console: the address is a mailto: link on the screen.
 */
final readonly class MessagesController
{
    public const string ARCHIVED = 'The message is archived.';

    public const string ALREADY_ARCHIVED = 'That message is already archived.';

    public function __construct(
        private ContactInbox $inbox,
        private ConsoleTime $time,
        private ConsoleSession $session,
        private TenantUrls $urls,
        private NotFoundController $notFound,
    ) {}

    public function index(ConsoleMessagesRequest $request): InertiaResponse
    {
        $view = $request->view();
        $page = $request->page();

        return Inertia::render('console/Messages', [
            'filters' => ['view' => $view->value],
            'messages' => function () use ($view, $page): array {
                $read = $this->inbox->page($view, $page);

                return [
                    'rows' => array_map($this->row(...), $read['records']),
                    'total' => $read['total'],
                    'page' => $page,
                    'page_size' => ContactInbox::PAGE_SIZE,
                ];
            },
        ]);
    }

    public function archive(ConsoleMessagesRequest $request, string $id): Response
    {
        $messageId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($messageId === false || ! $this->inbox->holds($messageId)) {
            return ($this->notFound)($request);
        }

        $operator = $this->session->operator($request) ?? throw new LogicException('A console write ran without an operator; its route declares no operator.');
        $archived = $this->inbox->archive($messageId, $operator->id);

        $request->session()->flash('flash', $archived ? ['kind' => 'success', 'message' => self::ARCHIVED] : ['kind' => 'error', 'message' => self::ALREADY_ARCHIVED]);

        return redirect($this->screen($request->view(), $request->page()));
    }

    /**
     * The screen's address for a view and a page, the page clamped to the last one the view still
     * fills, and the defaults, the open view and the first page, left out of the query.
     */
    private function screen(InboxView $view, int $page): string
    {
        $last = max(1, intdiv($this->inbox->total($view) + ContactInbox::PAGE_SIZE - 1, ContactInbox::PAGE_SIZE));
        $query = array_filter([
            'view' => $view === InboxView::Open ? null : $view->value,
            'page' => min($page, $last) > 1 ? (string) min($page, $last) : null,
        ]);

        return $this->urls->console('/messages'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * @return array{id: int, received: string, name: string, email: string, language: string, message: string}
     */
    private function row(ReceivedMessage $message): array
    {
        return [
            'id' => $message->id,
            'received' => $this->time->onScreen($message->receivedAt),
            'name' => $message->name,
            'email' => $message->email,
            'language' => $message->language->value,
            'message' => $message->message,
        ];
    }
}
