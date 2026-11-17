<?php

declare(strict_types=1);

namespace App\Http;

use App\Help\HelpSide;
use App\Help\HelpThreadMessage;
use App\Help\HelpTickets;
use App\Help\HelpTicketSummary;
use App\Site\Brand;
use App\Site\Demo;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The help bubble's props and its panel's, shared to every page a signed-in user of the business
 * sees and to nothing else: not the sign-in page, and not a support session, which has no bubble.
 *
 * Every page carries help alone: whether this viewer has tickets at all, false on the demo account,
 * which gets the answers and nothing more; whether any listed ticket is unread for the user; and
 * the ticket a link from a mail opened the admin on, for the one page load after it. The bubble's
 * own words ride in the shell's section, which every page loads.
 *
 * The panel's four props are sent only when a partial reload names them, so a page that never
 * opens the panel pays for none of them: help_panel, the panel's words and the answers, in the
 * request's language, the user's; help_tickets, the business's fifty tickets with the newest
 * activity; and help_thread, the thread the user's last write named, by the session's flash, which
 * the help routes set and nothing in the request can. On the demo the list is empty and the thread
 * null. A platform message is named for the business by the platform's support name, never by its
 * operator, and a message is numbered by its place in its thread, from one, oldest first: the
 * business is sent no number that counts the platform's rows, a ticket's or a message's.
 */
final readonly class HelpProps
{
    /** The flash naming the thread help_thread sends: the one the user's last help write was about. */
    public const string THREAD = 'help_thread';

    /** The flash naming the ticket a link from a mail opened the admin on. */
    public const string OPEN = 'help_open';

    private const string SECTION = 'help';

    private const string ANSWERS = 'admin/help_answers';

    private const string PLATFORM = 'admin/help.platform';

    public function __construct(
        private HelpTickets $tickets,
        private Demo $demo,
        private Brand $brand,
        private AdminStrings $strings,
        private Translator $translator,
    ) {}

    /**
     * The help props of a signed-in principal's page: none for a support session.
     *
     * @return array<string, mixed>
     */
    public function for(Request $request, Tenant $tenant, Principal $principal): array
    {
        $userId = $principal->kind === Principal::USER ? $principal->ownStaffUserId() : null;

        if ($userId === null) {
            return [];
        }

        $demo = $this->demo->is($tenant);

        return [
            'help' => fn (): array => [
                'tickets' => ! $demo,
                'unread' => ! $demo && $this->tickets->unread($tenant->id, $userId),
                'open' => $demo ? null : self::flashed($request, self::OPEN),
            ],
            'help_panel' => Inertia::optional(fn (): array => ['words' => $this->strings->section(self::SECTION), 'answers' => $this->answers()]),
            'help_tickets' => Inertia::optional(fn (): array => $demo ? [] : array_map(self::row(...), $this->tickets->list($tenant->id, $userId))),
            'help_thread' => Inertia::optional(fn (): ?array => $demo ? null : $this->thread($request, $tenant, $userId)),
        ];
    }

    /**
     * @return array{uuid: string, subject: string, status: string, messages: list<array{id: int, side: string, name: string, mine: bool, body: string, at: string}>}|null
     */
    private function thread(Request $request, Tenant $tenant, int $userId): ?array
    {
        $uuid = self::flashed($request, self::THREAD);
        $thread = $uuid === null ? null : $this->tickets->thread($tenant->id, $uuid);

        if ($thread === null) {
            return null;
        }

        $platform = AdminLines::line(self::PLATFORM, ['brand' => $this->brand->name]);

        return [
            'uuid' => $thread->uuid,
            'subject' => $thread->subject,
            'status' => $thread->status->value,
            'messages' => array_map(fn (HelpThreadMessage $message, int $before): array => [
                'id' => $before + 1,
                'side' => $message->side->value,
                'name' => $message->side === HelpSide::Platform ? $platform : $message->authorName,
                'mine' => $message->userId === $userId,
                'body' => $message->body,
                'at' => BookingPayload::instant($message->at),
            ], $thread->messages, array_keys($thread->messages)),
        ];
    }

    /**
     * @return array{uuid: string, subject: string, status: string, last_message_at: string, unread: bool}
     */
    private static function row(HelpTicketSummary $ticket): array
    {
        return [
            'uuid' => $ticket->uuid,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'last_message_at' => BookingPayload::instant($ticket->lastMessageAt),
            'unread' => $ticket->unread,
        ];
    }

    /**
     * The answers file in the request's language as a list of topics in its order, each with its
     * answers in theirs, keyed as the file keys them.
     *
     * @return list<array{key: string, title: string, answers: list<array{key: string, question: string, answer: list<string>}>}>
     */
    private function answers(): array
    {
        $file = $this->translator->get(self::ANSWERS);
        $topics = [];

        foreach (is_array($file) ? $file : [] as $key => $topic) {
            if (! is_string($key) || ! is_array($topic) || ! is_string($topic['title'] ?? null) || ! is_array($topic['answers'] ?? null)) {
                continue;
            }

            $answers = [];

            foreach ($topic['answers'] as $answerKey => $answer) {
                if (! is_string($answerKey) || ! is_array($answer) || ! is_string($answer['question'] ?? null) || ! is_array($answer['answer'] ?? null)) {
                    continue;
                }

                $answers[] = ['key' => $answerKey, 'question' => $answer['question'], 'answer' => array_values(array_filter($answer['answer'], is_string(...)))];
            }

            $topics[] = ['key' => $key, 'title' => $topic['title'], 'answers' => $answers];
        }

        return $topics;
    }

    private static function flashed(Request $request, string $key): ?string
    {
        $uuid = $request->hasSession() ? $request->session()->get($key) : null;

        return is_string($uuid) ? $uuid : null;
    }
}
