<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Help\HelpAuthor;
use App\Help\HelpDesk;
use App\Help\HelpTickets;
use App\Models\User;
use App\Platform\Operator;
use App\Site\SiteLanguage;
use Illuminate\Support\Facades\DB;
use LogicException;
use Normalizer;
use Pest\Browser\Api\Webpage;
use Tests\Support\RecordingPlatformMessages;

/**
 * The help bubble's walks in one place: the panel's parts as selectors, the scripts that read
 * what a browser shows of it, the rows a walk starts from, and the answers' search worked out
 * again here from the language files, so a walk knows what the panel should find for a query
 * without a word of it typed in a test.
 */
final class Help
{
    public const string BUBBLE = '.admin-help-bubble';

    /** The dot on the bubble saying a ticket has a reply the viewer has not read. */
    public const string DOT = '.admin-help-bubble__dot';

    public const string PANEL = '.admin-help-panel';

    public const string SEARCH = '.admin-help-panel input[type="search"]';

    public const string ANSWERS_TAB = '.admin-help-tab[id$="-trigger-answers"]';

    public const string TICKETS_TAB = '.admin-help-tab[id$="-trigger-tickets"]';

    /** The dot on the Tickets tab, the bubble's own on the panel. */
    public const string TAB_DOT = '.admin-help-tab__dot';

    /** The button over the ticket list that opens the form. */
    public const string LIST_OPEN = '.admin-help-list > button';

    /** The button at the foot of the answers that opens the form. */
    public const string FOOT_OPEN = '.admin-help-still button';

    public const string SUBJECT = '#admin-help-subject';

    public const string MESSAGE = '#admin-help-body';

    public const string SEND = '.admin-help-form > button[type="submit"]';

    public const string ROW = '.admin-help-row';

    public const string THREAD_SUBJECT = '.admin-help-thread h3';

    /** The ticket's status, under its subject in the thread. */
    public const string THREAD_STATUS = '.admin-help-thread h3 + span';

    /** The way back from a thread to the list. */
    public const string BACK_FROM_THREAD = '.admin-help-thread > button';

    public const string BUSINESS_MESSAGE = '.admin-help-message--business';

    public const string PLATFORM_MESSAGE = '.admin-help-message--platform';

    public const string REPLY = '#admin-help-reply';

    public const string SEND_REPLY = '.admin-help-reply button[type="submit"]';

    public const string CLOSE = '.admin-help-close';

    /** The tab shown, read off the tab contents not hidden and drawn; both stay in the page. */
    public const string SHOWN_TAB = <<<'JS'
        (() => [...document.querySelectorAll('.admin-help-panel [role="tabpanel"]')]
            .filter((tab) => !tab.hidden && tab.getClientRects().length > 0)
            .map((tab) => tab.id.replace(/^.*-content-/, ''))
            .join(','))()
        JS;

    /** True when the panel covers the whole viewport, its width the box the document is laid out in. */
    public const string PANEL_FILLS_SCREEN = <<<'JS'
        (() => {
            const panel = document.querySelector('.admin-help-panel');
            if (panel === null) {
                return false;
            }
            const near = (a, b) => Math.abs(a - b) <= 1;
            const box = panel.getBoundingClientRect();
            return near(box.left, 0) && near(box.top, 0) && near(box.width, document.documentElement.clientWidth) && near(box.height, window.innerHeight);
        })()
        JS;

    /** Where focus is, said as the element's tag and class, or "body". */
    public const string FOCUS = <<<'JS'
        (() => {
            const active = document.activeElement;
            return active === null || active === document.body ? 'body' : `${active.tagName.toLowerCase()}.${String(active.className).split(' ')[0]}`;
        })()
        JS;

    /** True when focus is inside the screen's open dialog. */
    public const string FOCUS_IN_DIALOG = "document.activeElement !== null && document.activeElement.closest('.admin-modal__frame') !== null";

    /**
     * Starts a count, from this moment, of the times the panel is put into the page and of the
     * times it is taken out of it, kept on the window for PANEL_SINCE to read. A walk starts it
     * before a press or a key and reads it once focus has settled after, so what the panel did in
     * between is known whole, a panel that came and went again included, and no clock is read.
     */
    public const string PANEL_WATCH = <<<'JS'
        (() => {
            window.helpPanelWatch?.observer.disconnect();
            const seen = { opened: 0, closed: 0 };
            const panels = (nodes) => [...nodes].filter((node) => node instanceof Element && (node.matches('.admin-help-panel') || node.querySelector('.admin-help-panel') !== null)).length;
            const count = (records) => records.forEach((record) => {
                seen.opened += panels(record.addedNodes);
                seen.closed += panels(record.removedNodes);
            });
            const observer = new MutationObserver(count);
            observer.observe(document.body, { childList: true, subtree: true });
            window.helpPanelWatch = {
                observer,
                read: () => {
                    count(observer.takeRecords());
                    return { ...seen, open: document.querySelector('.admin-help-panel') !== null };
                },
            };
            return true;
        })()
        JS;

    /**
     * What the panel has done since PANEL_WATCH began: the times it was put into the page, the
     * times it was taken out of it, and whether it is in the page now.
     */
    public const string PANEL_SINCE = 'window.helpPanelWatch.read()';

    /**
     * What stands over the bubble's middle, and whether assistive technology can reach it. The
     * bubble is made hit-testable for the reading alone, since a dialog that holds the page takes
     * pointer events from everything behind it and a hit test would then skip the bubble wherever
     * it was drawn; what is read is the order things are painted in at that point.
     */
    public const string OVER_BUBBLE = <<<'JS'
        (() => {
            const bubble = document.querySelector('.admin-help-bubble');
            if (bubble === null) {
                return { bubble: false };
            }
            const box = bubble.getBoundingClientRect();
            const was = bubble.style.pointerEvents;
            bubble.style.pointerEvents = 'auto';
            const stack = document.elementsFromPoint(box.left + box.width / 2, box.top + box.height / 2);
            bubble.style.pointerEvents = was;
            const at = stack.findIndex((element) => bubble.contains(element));
            const over = at <= 0 ? null : stack[0];
            return {
                bubble: true,
                covered: at !== 0,
                over: over === null ? null : String(over.className).split(' ')[0],
                hidden: bubble.closest('[aria-hidden="true"], [inert]') !== null,
            };
        })()
        JS;

    /**
     * The controls the screen shows under the bubble, at whatever width it is drawn, once the
     * window and every scroller inside it are at their ends, which is where the controls at the
     * foot of the screen come nearest the bubble. They are answered as the start of each one's
     * markup: none, where the foot of the screen keeps clear of it.
     */
    public const string UNDER_BUBBLE = <<<'JS'
        new Promise((resolve) => {
            const scrollers = [document.scrollingElement, ...document.querySelectorAll('*')].filter((element) => {
                if (element === null || element.scrollHeight <= element.clientHeight + 1) {
                    return false;
                }
                return element === document.scrollingElement || ['auto', 'scroll'].includes(getComputedStyle(element).overflowY);
            });
            scrollers.forEach((element) => {
                element.scrollTop = element.scrollHeight;
            });
            requestAnimationFrame(() => requestAnimationFrame(() => {
                const bubble = document.querySelector('.admin-help-bubble');
                if (bubble === null) {
                    resolve(['no bubble']);
                    return;
                }
                const b = bubble.getBoundingClientRect();
                const controls = document.querySelectorAll('a[href], button, input, select, textarea, [role="button"], [tabindex]:not([tabindex="-1"])');
                resolve([...controls]
                    .filter((control) => !bubble.contains(control) && control.closest('.admin-toaster, [aria-hidden="true"]') === null)
                    .filter((control) => {
                        const r = control.getBoundingClientRect();
                        return r.width > 0 && r.height > 0 && r.left < b.right && r.right > b.left && r.top < b.bottom && r.bottom > b.top;
                    })
                    .map((control) => control.outerHTML.slice(0, 120)));
            }));
        })
        JS;

    /**
     * Signs the walk's operator in to the console in the page given and waits for the tenants' list.
     *
     * @param  Webpage  $page
     * @return Webpage
     */
    public static function consoleSignIn($page)
    {
        return $page->navigate(Console::url('/login'))
            ->fill('email', 'pat@inbox.example')
            ->fill('password', 'operator password')
            ->click('.admin-console-login-submit')
            ->assertSee('2 of 2');
    }

    /** Opens a ticket as the user with the email given, straight through the tickets, and answers its uuid. */
    public static function ticket(string $email, string $subject, string $body): string
    {
        $user = User::query()->where('email', $email)->sole();

        return app(HelpTickets::class)->open($user->tenant_id, new HelpAuthor($user->id, $user->name, $user->role), $subject, $body);
    }

    /** Writes the business's next message on a ticket, as the user with the email given. */
    public static function write(string $email, string $uuid, string $body): void
    {
        $user = User::query()->where('email', $email)->sole();

        app(HelpTickets::class)->reply($user->tenant_id, new HelpAuthor($user->id, $user->name, $user->role), $uuid, $body);
    }

    /** The operator's reply on a ticket, straight through the desk. */
    public static function answer(string $uuid, Operator $operator, string $body): void
    {
        app(HelpDesk::class)->reply(self::id($uuid), $operator, $body);
    }

    /** Closes a ticket as the operator given, straight through the desk. */
    public static function close(string $uuid, Operator $operator): void
    {
        app(HelpDesk::class)->close(self::id($uuid), $operator->id);
    }

    /** The ticket's number, which the console spells its address with. */
    public static function id(string $uuid): int
    {
        $id = DB::table('help_tickets')->where('uuid', $uuid)->value('id');

        if (! is_int($id)) {
            throw new LogicException(sprintf('No ticket has the uuid %s.', $uuid));
        }

        return $id;
    }

    /** The only ticket there is, as its uuid. */
    public static function onlyTicket(): string
    {
        $uuids = DB::table('help_tickets')->pluck('uuid')->all();

        if (count($uuids) !== 1 || ! is_string($uuids[0])) {
            throw new LogicException(sprintf('%d tickets stand where one was looked for.', count($uuids)));
        }

        return $uuids[0];
    }

    /**
     * The mails of the kind given the recorder holds, oldest first.
     *
     * @return list<PlatformMessage>
     */
    public static function sent(RecordingPlatformMessages $seam, PlatformMessageKind $kind): array
    {
        return array_values(array_filter($seam->sent, fn (PlatformMessage $message): bool => $message->kind === $kind));
    }

    /**
     * Text as the search compares it: its accents taken off, in small letters, every final sigma
     * the sigma written inside a word.
     */
    public static function fold(string $text): string
    {
        return str_replace("\u{03C2}", "\u{03C3}", mb_strtolower(self::unmarked($text)));
    }

    /** The text with its accents taken off and its letters as they were. */
    public static function bare(string $text): string
    {
        $composed = Normalizer::normalize(self::unmarked($text), Normalizer::FORM_C);

        return is_string($composed) ? $composed : throw new LogicException('The text could not be composed again.');
    }

    /** The text taken apart into letters and marks, the marks left out. */
    private static function unmarked(string $text): string
    {
        $parts = Normalizer::normalize($text, Normalizer::FORM_D);
        $unmarked = is_string($parts) ? preg_replace('/\p{M}/u', '', $parts) : null;

        return is_string($unmarked) ? $unmarked : throw new LogicException('The text could not be taken apart.');
    }

    /**
     * The questions of the answers a search finds, in the panel's order: every word of the query
     * inside the answer's question or its paragraphs, compared folded.
     *
     * @return list<string>
     */
    public static function matching(SiteLanguage $language, string $query): array
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', self::fold($query)) ?: [], fn (string $word): bool => $word !== ''));
        $found = [];

        foreach (self::answers($language) as [$question, $text]) {
            $folded = self::fold($text);
            $all = true;

            foreach ($words as $word) {
                $all = $all && str_contains($folded, $word);
            }

            if ($all && $words !== []) {
                $found[] = $question;
            }
        }

        return $found;
    }

    /**
     * The first word of six letters or more in the answers' Greek questions that carries an
     * accent and ends in the final sigma.
     */
    public static function greekWord(): string
    {
        foreach (self::answers(SiteLanguage::Greek) as [$question]) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $question) ?: [] as $word) {
                if (mb_strlen($word) >= 6 && str_ends_with($word, "\u{03C2}") && self::bare($word) !== $word) {
                    return $word;
                }
            }
        }

        throw new LogicException('No Greek question holds an accented word ending in the final sigma.');
    }

    /**
     * Each answer of the file in the language given as the panel names it in its markup, its
     * topic's key and its own joined by a dash, in the file's order.
     *
     * @return list<string>
     */
    public static function answerNames(SiteLanguage $language): array
    {
        $file = trans('admin/help_answers', [], $language->value);
        $names = [];

        foreach (is_array($file) ? $file : [] as $topic => $content) {
            foreach (is_array($content) && is_array($content['answers'] ?? null) ? array_keys($content['answers']) : [] as $answer) {
                $names[] = $topic.'-'.$answer;
            }
        }

        return $names;
    }

    /**
     * Each answer of the file in the language given, in its order, as its question and the
     * question and paragraphs joined, the text the search looks in.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function answers(SiteLanguage $language): array
    {
        $file = trans('admin/help_answers', [], $language->value);
        $answers = [];

        foreach (is_array($file) ? $file : [] as $topic) {
            foreach (is_array($topic) && is_array($topic['answers'] ?? null) ? $topic['answers'] : [] as $answer) {
                if (is_array($answer) && is_string($answer['question'] ?? null) && is_array($answer['answer'] ?? null)) {
                    $answers[] = [$answer['question'], implode("\n", [$answer['question'], ...array_filter($answer['answer'], is_string(...))])];
                }
            }
        }

        return $answers;
    }
}
