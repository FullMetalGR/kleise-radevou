import { Dialog as DialogPrimitive } from 'radix-ui';
import { act, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { key, mount, type Mounted, onPhone, press, settle } from '@/components/ui/harness';
import { formatInstant, type TenantClock } from '@/lib/format';
import { stringsOf } from '@/lib/strings';
import { keyedShell, keyedTable } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import { ADMIN_LINES_EN } from '../lines.generated';
import { HelpBubble } from './HelpBubble';
import type { HelpBubble as Bubbled, HelpThread, HelpTicketRow, HelpTopic } from './types';
import type { HelpStrings } from './words';

/*
 * The help bubble and its panel, with the framework stood in for by a router that records each
 * visit and the failure listeners registered around it, and each visit ended by hand: landed with
 * the props it asked for, cancelled, or refused. The bubble's name and its dot; the words and the
 * answers asked for on the first opening alone; the answers under their topics and the search;
 * the list read each time the Tickets tab opens; a thread read by its route and shown only when it
 * is the ticket asked for; a new ticket and a reply sent, refused under their fields, and kept as
 * drafts until sent; the answers alone for a viewer with no tickets; the panel filling the screen
 * below the breakpoint; and the ticket a mail links to opened once no dialog is over the screen.
 *
 * The tab not chosen is in the page and hidden. At the desktop width the panel closes on a press
 * or a focus outside it, a toast and the bubble apart, and only a close that did not come from
 * outside hands focus back to the bubble. A read asked for while a send is on its way runs when
 * the send ends; a send that ended with no answer has what would show it read again. There is no
 * layout here, so where a thread is scrolled is read off the calls that scroll it.
 */

const shell = keyedShell();

const KEYED_HELP: HelpStrings = keyedTable<HelpStrings>(ADMIN_LINES_EN.help);

/** The panel's words, keyed, which the tests read their expectations through. */
const words = stringsOf<HelpStrings>(KEYED_HELP, 'en');

type Visit = {
    only?: string[];
    async?: boolean;
    onStart?: () => void;
    onSuccess?: (page: { props: object }) => void;
    onFinish?: () => void;
};

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/admin/calendar',
    post: vi.fn(),
    reload: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: inertia.props, url: inertia.url }),
    router: {
        post: inertia.post,
        reload: inertia.reload,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const TOPICS: HelpTopic[] = [
    {
        key: 'calendar',
        title: 'The calendar',
        answers: [
            { key: 'move', question: 'How do I move a booking?', answer: ['Drag it to its new time.', 'The customer gets an email.'] },
            { key: 'close', question: 'How do I close a day?', answer: ['Add a closure.'] },
        ],
    },
    { key: 'team', title: 'The team', answers: [{ key: 'invite', question: 'How do I invite a colleague?', answer: ['Send an invitation.'] }] },
];

const PANEL = { words: KEYED_HELP, answers: TOPICS };

const ROWS: HelpTicketRow[] = [
    { uuid: 'aaaa-1', subject: 'The calendar is slow', status: 'open', last_message_at: '2026-11-17T10:30:00Z', unread: true },
    { uuid: 'bbbb-2', subject: 'An invoice question', status: 'closed', last_message_at: '2026-11-10T08:00:00Z', unread: false },
];

const SLOW: HelpThread = {
    uuid: 'aaaa-1',
    subject: 'The calendar is slow',
    status: 'closed',
    messages: [
        { id: 1, side: 'business', name: 'Maria Owner', mine: true, body: 'It takes long to load.\nEvery morning.', at: '2026-11-16T07:00:00Z' },
        { id: 2, side: 'platform', name: 'Brand Support', mine: false, body: 'We are looking into it.', at: '2026-11-17T10:30:00Z' },
    ],
};

const INVOICE: HelpThread = {
    uuid: 'bbbb-2',
    subject: 'An invoice question',
    status: 'open',
    messages: [{ id: 1, side: 'business', name: 'Maria Owner', mine: true, body: 'Where is it?', at: '2026-11-10T08:00:00Z' }],
};

function bubbled(overrides: Partial<Bubbled> = {}): Bubbled {
    return { tickets: true, unread: false, open: null, ...overrides };
}

function propsWith(help: Bubbled | undefined): Record<string, unknown> {
    return { help, tenant: { timezone: CLOCK.timezone }, settings: { date_format: CLOCK.dateFormat, time_format: CLOCK.timeFormat } };
}

function q(selector: string): Element | null {
    return document.body.querySelector(selector);
}

function all(selector: string): Element[] {
    return [...document.body.querySelectorAll(selector)];
}

/** The button shown whose text is the line given; the tab not chosen stays in the page, hidden. */
function button(text: string): Element | null {
    return all('button').find((element) => element.textContent?.trim() === text && element.closest('[hidden]') === null) ?? null;
}

function bubble(): Element | null {
    return q('.admin-help-bubble');
}

function panel(): Element | null {
    return q('[role="dialog"]');
}

function lastReload(): Visit {
    return (inertia.reload.mock.lastCall?.[0] ?? {}) as Visit;
}

function lastPost(): [string, Record<string, string>, Visit] {
    return (inertia.post.mock.lastCall ?? []) as [string, Record<string, string>, Visit];
}

/** The visit ends as the framework ends one that landed, with the props given. */
function land(visit: Visit, props: object): void {
    act(() => visit.onStart?.());
    act(() => visit.onSuccess?.({ props }));
    act(() => visit.onFinish?.());
}

/** The visit ends with no answer, as one another visit cancelled does. */
function cancel(visit: Visit): void {
    act(() => visit.onStart?.());
    act(() => visit.onFinish?.());
}

/** The visit fails with no answer at all, as one whose connection dropped does. */
function lose(visit: Visit): void {
    const event = new CustomEvent('exception', { cancelable: true, detail: { exception: new Error('lost') } });

    act(() => visit.onStart?.());
    act(() => inertia.listeners.get('exception')?.(event));
    act(() => visit.onFinish?.());
}

/** The visit fails with the response given, as the framework reports a non-page answer. */
function refuse(visit: Visit, response: unknown): void {
    const event = new CustomEvent('invalid', { cancelable: true, detail: { response } });

    act(() => visit.onStart?.());
    act(() => inertia.listeners.get('invalid')?.(event));
    act(() => visit.onFinish?.());
}

/** The house envelope of a refusal, its words a neutral token, beside the field it names. */
function refusal(detail: string, field?: string): unknown {
    return { status: 429, data: { code: 'help_limited', message: 'help_limited', data: { status: 429, detail, ...(field === undefined ? {} : { field }) } } };
}

/** A value typed into a field as a browser fires it, the React way. */
function typeInto(element: Element | null, value: string): void {
    if (!(element instanceof HTMLInputElement) && !(element instanceof HTMLTextAreaElement)) {
        throw new Error('Nothing to type into.');
    }

    const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value')?.set;

    act(() => {
        setter?.call(element, value);
        element.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/** A form sent past its button, as a press that came before the button was drawn as busy would send it. */
function submit(form: Element | null): void {
    if (form === null) {
        throw new Error('No form to send.');
    }

    act(() => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
}

/** A tab chosen as a pointer chooses one: the tabs act on the press itself. */
function chooseTab(text: string): void {
    const tab = all('[role="tab"]').find((element) => element.textContent?.trim() === text);

    if (tab === undefined) {
        throw new Error(`No tab ${text}.`);
    }

    act(() => tab.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0 })));
}

let view: Mounted;

let undo: (() => void) | null = null;

/** What the page holds beside the panel for one test, gone with it. */
const strays: Element[] = [];

/** Every element brought into view, with how; the browser's own call, which has no layout to act on here. */
const scrolls: { element: Element; options: unknown }[] = [];

const scrollIntoView = Element.prototype.scrollIntoView;

/** The bubble, and beside it whatever else the screen holds. */
function render(beside: ReactNode = null): void {
    view.render(
        <ToastProvider>
            <HelpBubble />
            {beside}
        </ToastProvider>,
    );
}

/** A control of the page, outside the panel. */
function pageButton(): HTMLButtonElement {
    const control = document.createElement('button');

    document.body.append(control);
    strays.push(control);

    return control;
}

/**
 * A whole press of the primary button as a browser delivers it: the pointer down, focus on what
 * was pressed, then the click, the screen brought up to date after the page's own handlers and
 * before the document hears the click, as a browser does between the two. A press is counted as
 * one outside the panel only when its click arrives, and only a pointer's click, which carries
 * the count of its presses, is that click: a key's carries none.
 */
function pressWhole(element: Element | null): void {
    if (!(element instanceof HTMLElement)) {
        throw new Error('Nothing to press.');
    }

    const upToDate = () => flushSync(() => undefined);

    act(() => {
        element.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, button: 0 }));
        element.focus();
    });
    document.body.addEventListener('click', upToDate, { once: true });
    act(() => element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 })));
    document.body.removeEventListener('click', upToDate);
}

/** A dialog open over the screen, as a screen's own is. */
function over(onOpenChange: (open: boolean) => void): ReactNode {
    return (
        <DialogPrimitive.Root open onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Content className="over-the-screen" aria-describedby={undefined}>
                    <DialogPrimitive.Title>Over</DialogPrimitive.Title>
                    <button type="button">Inside</button>
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

function field(selector: string): HTMLInputElement | HTMLTextAreaElement {
    const element = q(selector);

    if (!(element instanceof HTMLInputElement) && !(element instanceof HTMLTextAreaElement)) {
        throw new Error(`No field ${selector}.`);
    }

    return element;
}

/** Which of the two tabs' contents are hidden, the answers' first. */
function hiddenTabs(): boolean[] {
    return all('[role="tabpanel"]').map((content) => content.hasAttribute('hidden'));
}

/** The panel opened from the bubble, its words and answers landing with the first opening. */
function openPanel(): void {
    press(bubble());

    if (inertia.reload.mock.calls.length > 0 && panel() === null) {
        land(lastReload(), { help_panel: PANEL, help: bubbled() });
    }
}

/** The Tickets tab opened on the list, which lands with the rows given. */
function openList(rows: HelpTicketRow[] = ROWS): void {
    openPanel();
    chooseTab(words.text('tabs.tickets'));
    land(lastReload(), { help_tickets: rows, help: bubbled() });
}

beforeEach(() => {
    inertia.props = propsWith(bubbled());
    inertia.url = '/admin/calendar';
    inertia.post.mockReset();
    inertia.reload.mockReset();
    inertia.listeners.clear();
    scrolls.length = 0;
    Element.prototype.scrollIntoView = function (this: Element, options?: boolean | ScrollIntoViewOptions) {
        scrolls.push({ element: this, options });
    };
    view = mount();
});

afterEach(() => {
    view.unmount();
    undo?.();
    undo = null;
    strays.splice(0).forEach((stray) => stray.remove());
    Element.prototype.scrollIntoView = scrollIntoView;
});

describe('the help bubble', () => {
    it('is not there on a screen whose props carry no help', () => {
        inertia.props = propsWith(undefined);
        render();

        expect(bubble()).toBeNull();
    });

    it('carries the bubble\'s name, and the dot said in that name when a ticket has an unread reply', () => {
        render();

        expect(bubble()?.getAttribute('aria-label')).toBe(shell.text('help.name'));
        expect(q('.admin-help-bubble__dot')).toBeNull();

        inertia.props = propsWith(bubbled({ unread: true }));
        render();

        expect(bubble()?.getAttribute('aria-label')).toBe(shell.text('help.unread'));
        expect(q('.admin-help-bubble__dot')?.getAttribute('aria-hidden')).toBe('true');
    });

    it('asks for the words and the answers on the first opening alone, busy until they land, and opens on the search', () => {
        render();
        press(bubble());

        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(lastReload().only).toEqual(['help_panel', 'help']);
        expect(lastReload().async).toBe(true);
        expect(bubble()?.getAttribute('aria-busy')).toBe('true');
        expect(panel()).toBeNull();

        land(lastReload(), { help_panel: PANEL, help: bubbled() });

        expect(bubble()?.hasAttribute('aria-busy')).toBe(false);
        expect(panel()?.querySelector('h2')?.textContent).toBe(shell.text('help.name'));
        expect(document.activeElement?.getAttribute('aria-label')).toBe(words.text('search.label'));

        press(button(words.text('close')));
        expect(panel()).toBeNull();

        press(bubble());
        expect(panel()).not.toBeNull();
        expect(inertia.reload).toHaveBeenCalledTimes(1);
    });

    it('leaves the bubble idle and the panel shut when the first read is cancelled, and asks again on the next press', () => {
        render();
        press(bubble());
        cancel(lastReload());

        expect(bubble()?.hasAttribute('aria-busy')).toBe(false);
        expect(panel()).toBeNull();

        press(bubble());
        expect(inertia.reload).toHaveBeenCalledTimes(2);
    });

    it('closes on Escape, and when the screen changes under it', () => {
        render();
        openPanel();
        key(panel(), 'Escape');
        expect(panel()).toBeNull();

        openPanel();
        inertia.url = '/admin/bookings?page=2';
        render();
        expect(panel()).toBeNull();
    });
});

describe('the help panel\'s answers', () => {
    it('hides the tab not chosen after each change of tab, both staying in the page with what was typed in them', () => {
        render();
        openPanel();

        expect(hiddenTabs()).toEqual([false, true]);
        expect(button(words.text('open'))?.closest('.admin-help-still')).not.toBeNull();

        typeInto(q('[role="tabpanel"] input'), 'move');
        chooseTab(words.text('tabs.tickets'));
        land(lastReload(), { help_tickets: ROWS, help: bubbled() });

        expect(hiddenTabs()).toEqual([true, false]);
        expect(q('.admin-help-still')).not.toBeNull();
        expect(button(words.text('open'))?.closest('.admin-help-list')).not.toBeNull();

        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'Half a subject');
        chooseTab(words.text('tabs.answers'));

        expect(hiddenTabs()).toEqual([false, true]);
        expect(field('[role="tabpanel"] input').value).toBe('move');
        expect(field('#admin-help-subject').value).toBe('Half a subject');
        expect(button(words.text('form.send'))).toBeNull();

        chooseTab(words.text('tabs.tickets'));

        expect(hiddenTabs()).toEqual([true, false]);
        expect(button(words.text('form.send'))).not.toBeNull();
    });

    it('shows the answers under their topics, one open at a time', () => {
        render();
        openPanel();

        expect(all('.admin-help-topic h3').map((heading) => heading.textContent)).toEqual(['The calendar', 'The team']);

        const [move, close] = all('.admin-help-question');
        press(move ?? null);
        expect(move?.getAttribute('aria-expanded')).toBe('true');
        expect(q(`#${move?.getAttribute('aria-controls') ?? ''}`)?.hasAttribute('hidden')).toBe(false);

        press(close ?? null);
        expect(move?.getAttribute('aria-expanded')).toBe('false');
        expect(close?.getAttribute('aria-expanded')).toBe('true');
    });

    it('searches into one list with a count, and says when nothing matches beside the button that opens a ticket, its message the search', () => {
        render();
        openPanel();

        typeInto(q('input[type="search"]'), 'invite');
        expect(q('[role="status"]')?.textContent).toBe(words.choice('search.found', 1));
        expect(all('.admin-help-question').map((question) => question.textContent)).toEqual(['How do I invite a colleague?+']);

        typeInto(q('input[type="search"]'), 'refund policy');
        expect(q('[role="status"]')?.textContent).toBe(words.text('search.none'));
        expect(all('.admin-help-question')).toHaveLength(0);

        press(button(words.text('open')));
        expect(q('[role="tab"][aria-selected="true"]')?.textContent).toBe(words.text('tabs.tickets'));
        expect((q('#admin-help-body') as HTMLTextAreaElement | null)?.value).toBe('refund policy');
        expect(document.activeElement?.id).toBe('admin-help-subject');
    });

    it('shows the answers alone, with no tabs and no offer of a ticket, to a viewer with no tickets', () => {
        inertia.props = propsWith(bubbled({ tickets: false }));
        render();
        press(bubble());
        land(lastReload(), { help_panel: PANEL, help: bubbled({ tickets: false }) });

        expect(q('[role="tablist"]')).toBeNull();
        expect(all('.admin-help-question')).toHaveLength(3);

        typeInto(q('input[type="search"]'), 'refund');
        expect(q('[role="status"]')?.textContent).toBe(words.text('search.none'));
        expect(button(words.text('open'))).toBeNull();
    });
});

describe('the help panel\'s tickets', () => {
    it('reads the list each time the Tickets tab opens, each row with its subject, status, last message and dot in words', () => {
        render();
        openPanel();
        chooseTab(words.text('tabs.tickets'));

        expect(lastReload().only).toEqual(['help_tickets', 'help']);
        expect(q('.admin-help-list')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-help-list [role="status"]')?.textContent).toBe(words.text('list.loading'));

        land(lastReload(), { help_tickets: ROWS, help: bubbled() });

        const rows = all('.admin-help-row');
        expect(rows.map((row) => row.querySelector('.truncate')?.textContent)).toEqual(['The calendar is slow', 'An invoice question']);
        expect(rows[0]?.textContent).toContain(words.text('statuses.open'));
        expect(rows[0]?.textContent).toContain(words.text('list.last', { time: formatInstant(ROWS[0]?.last_message_at ?? '', CLOCK) }));
        expect(rows[0]?.textContent).toContain(words.text('list.unread'));
        // The dot's words are small text, so they take the deepened primary, which holds the
        // text floor in every theme where the raw one does not.
        expect(rows[0]?.querySelector('.admin-help-unread')?.classList.contains('text-primary-deep')).toBe(true);
        expect(rows[0]?.querySelector('.admin-help-unread')?.classList.contains('text-primary')).toBe(false);
        expect(rows[1]?.textContent).toContain(words.text('statuses.closed'));
        expect(rows[1]?.textContent).not.toContain(words.text('list.unread'));

        chooseTab(words.text('tabs.answers'));
        chooseTab(words.text('tabs.tickets'));
        expect(inertia.reload).toHaveBeenCalledTimes(3);
    });

    it('says the Tickets tab has a new reply in words where a ticket does', () => {
        inertia.props = propsWith(bubbled({ unread: true }));
        render();
        press(bubble());
        land(lastReload(), { help_panel: PANEL, help: bubbled({ unread: true }) });

        expect(all('[role="tab"]')[1]?.getAttribute('aria-label')).toBe(words.text('tabs.tickets_unread'));
    });

    it('says there are no tickets yet when there is none, under the button that opens one', () => {
        render();
        openList([]);

        expect(q('.admin-help-empty')?.textContent).toBe(words.text('list.empty'));
        expect(button(words.text('open'))).not.toBeNull();
    });

    it('offers to try again where the list did not come back', () => {
        render();
        openPanel();
        chooseTab(words.text('tabs.tickets'));
        cancel(lastReload());

        expect(q('.admin-help-list')?.hasAttribute('aria-busy')).toBe(false);
        expect(q('.admin-help-failed')?.textContent).toContain(words.text('list.failed'));

        press(button(words.text('retry')));
        land(lastReload(), { help_tickets: ROWS, help: bubbled() });
        expect(q('.admin-help-failed')).toBeNull();
        expect(all('.admin-help-row')).toHaveLength(2);
    });

    it('reads a thread by its route and shows its messages oldest first, each with its writer and time, and a closed ticket\'s line', () => {
        render();
        openList();
        press(all('.admin-help-row')[0] ?? null);

        expect(lastPost()[0]).toBe('/admin/help/aaaa-1/read');
        expect(lastPost()[2].only).toEqual(['help_thread', 'help']);
        expect(q('.admin-help-thread')?.getAttribute('aria-busy')).toBe('true');
        expect(document.activeElement?.textContent).toBe(words.text('back'));

        land(lastPost()[2], { help_thread: SLOW, help: bubbled() });

        const messages = all('.admin-help-message');
        expect(messages.map((message) => message.querySelector('span')?.textContent)).toEqual(['Maria Owner', 'Brand Support']);
        expect(messages[0]?.querySelector('time')?.textContent).toBe(formatInstant('2026-11-16T07:00:00Z', CLOCK));
        expect(messages[0]?.querySelector('.whitespace-pre-line')?.textContent).toBe('It takes long to load.\nEvery morning.');
        expect(q('.admin-help-closed')?.textContent).toBe(words.text('thread.closed'));
    });

    it('asks once more for a thread that comes back as none or as another ticket, then shows the list, never the wrong thread', () => {
        render();
        openList();
        press(all('.admin-help-row')[0] ?? null);
        land(lastPost()[2], { help_thread: null, help: bubbled() });

        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(lastPost()[0]).toBe('/admin/help/aaaa-1/read');

        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });

        expect(q('.admin-help-thread')).toBeNull();
        expect(document.body.textContent).not.toContain('Where is it?');
        expect(lastReload().only).toEqual(['help_tickets', 'help']);
        expect(q('.admin-help-list')).not.toBeNull();
    });

    it('refuses an empty subject and message under their fields with focus on the first, and sends nothing', () => {
        render();
        openList();
        press(button(words.text('open')));
        press(button(words.text('form.send')));

        expect(inertia.post).not.toHaveBeenCalled();
        expect(q('#admin-help-subject')?.getAttribute('aria-invalid')).toBe('true');
        expect(q('#admin-help-subject-error')?.textContent).toBe(words.choice('validation.subject', 150));
        expect(q('#admin-help-body-error')?.textContent).toBe(words.choice('validation.body', 5000));
        expect(document.activeElement?.id).toBe('admin-help-subject');
    });

    it('sends a new ticket and shows its thread, the drafts kept across closing until sent', () => {
        render();
        openList();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'Two staff share a chair');
        typeInto(q('#admin-help-body'), 'How do I book them both?');

        press(button(words.text('close')));
        press(bubble());
        expect((q('#admin-help-subject') as HTMLInputElement | null)?.value).toBe('Two staff share a chair');

        press(button(words.text('form.send')));
        const [to, data, visit] = lastPost();
        expect(to).toBe('/admin/help');
        expect(data).toEqual({ subject: 'Two staff share a chair', body: 'How do I book them both?' });
        expect(visit.only).toEqual(['help_thread', 'help_tickets', 'help']);
        expect(q('.admin-help-form [aria-busy="true"]')?.textContent).toContain(words.text('form.sending'));

        const created: HelpThread = { uuid: 'cccc-3', subject: 'Two staff share a chair', status: 'open', messages: [{ id: 1, side: 'business', name: 'Maria Owner', mine: true, body: 'How do I book them both?', at: '2026-11-17T12:00:00Z' }] };
        land(visit, { help_thread: created, help_tickets: ROWS, help: bubbled() });

        expect(q('.admin-help-thread h3')?.textContent).toBe('Two staff share a chair');

        press(button(words.text('back')));
        press(button(words.text('open')));
        expect((q('#admin-help-subject') as HTMLInputElement | null)?.value).toBe('');
    });

    it('shows the list, the new ticket first, where the new ticket\'s thread does not come back', () => {
        render();
        openList();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'Two staff share a chair');
        typeInto(q('#admin-help-body'), 'How?');
        press(button(words.text('form.send')));

        const row: HelpTicketRow = { uuid: 'cccc-3', subject: 'Two staff share a chair', status: 'open', last_message_at: '2026-11-17T12:00:00Z', unread: false };
        land(lastPost()[2], { help_thread: null, help_tickets: [row, ...ROWS], help: bubbled() });

        expect(q('.admin-help-thread')).toBeNull();
        expect(all('.admin-help-row .truncate')[0]?.textContent).toBe('Two staff share a chair');
    });

    it('puts a refusal naming a field under it, and one naming none in a toast', () => {
        render();
        openList();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'A subject');
        typeInto(q('#admin-help-body'), 'A message');

        press(button(words.text('form.send')));
        refuse(lastPost()[2], refusal('ticket-limit-token', 'subject'));
        expect(q('#admin-help-subject-error')?.textContent).toBe('ticket-limit-token');
        expect(document.activeElement?.id).toBe('admin-help-subject');

        press(button(words.text('form.send')));
        refuse(lastPost()[2], refusal('demo-token'));
        expect(q('.admin-toaster')?.textContent).toContain('demo-token');
        expect((q('#admin-help-body') as HTMLTextAreaElement | null)?.value).toBe('A message');
    });

    it('sends a reply and shows the thread it lands with, the reply box then empty', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });

        typeInto(q('#admin-help-reply'), 'Line one\nLine two');
        press(button(words.text('thread.send')));

        const [to, data, visit] = lastPost();
        expect(to).toBe('/admin/help/bbbb-2/messages');
        expect(data).toEqual({ body: 'Line one\nLine two' });
        expect(visit.only).toEqual(['help_thread', 'help']);

        const replied: HelpThread = { ...INVOICE, messages: [...INVOICE.messages, { id: 2, side: 'business', name: 'Maria Owner', mine: true, body: 'Line one\nLine two', at: '2026-11-17T12:00:00Z' }] };
        land(visit, { help_thread: replied, help: bubbled() });

        expect(all('.admin-help-message')).toHaveLength(2);
        expect((q('#admin-help-reply') as HTMLTextAreaElement | null)?.value).toBe('');
    });

    it('refuses an empty reply under the reply box', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        press(button(words.text('thread.send')));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(q('#admin-help-reply-error')?.textContent).toBe(words.choice('validation.body', 5000));
        expect(document.activeElement?.id).toBe('admin-help-reply');
    });

    it('offers to try again where a thread is refused, and reads it on the retry', () => {
        render();
        openList();
        press(all('.admin-help-row')[0] ?? null);
        refuse(lastPost()[2], refusal('gone-token'));

        expect(q('.admin-help-thread')?.hasAttribute('aria-busy')).toBe(false);
        expect(q('.admin-help-failed')?.textContent).toContain(words.text('thread.failed'));
        expect(q('.admin-help-message')).toBeNull();

        press(button(words.text('retry')));
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(lastPost()[0]).toBe('/admin/help/aaaa-1/read');

        land(lastPost()[2], { help_thread: SLOW, help: bubbled() });
        expect(q('.admin-help-failed')).toBeNull();
        expect(all('.admin-help-message')).toHaveLength(2);
    });

    it('says the list is loading where it is opened while a reply is on its way, never read till then, and reads it when the send ends', async () => {
        inertia.props = propsWith(bubbled({ open: 'aaaa-1' }));
        render();
        await settle();
        land(lastReload(), { help_panel: PANEL, help: bubbled() });
        land(lastPost()[2], { help_thread: SLOW, help: bubbled() });

        typeInto(q('#admin-help-reply'), 'Any news?');
        press(button(words.text('thread.send')));
        const send = lastPost()[2];
        press(button(words.text('back')));

        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(q('.admin-help-list')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-help-list [role="status"]')?.textContent).toBe(words.text('list.loading'));
        expect(q('.admin-help-failed')).toBeNull();

        const replied: HelpThread = { ...SLOW, messages: [...SLOW.messages, { id: 3, side: 'business', name: 'Maria Owner', mine: true, body: 'Any news?', at: '2026-11-17T12:00:00Z' }] };
        land(send, { help_thread: replied, help: bubbled() });

        expect(inertia.reload).toHaveBeenCalledTimes(2);
        expect(lastReload().only).toEqual(['help_tickets', 'help']);
        expect(q('.admin-help-list')?.getAttribute('aria-busy')).toBe('true');

        land(lastReload(), { help_tickets: ROWS, help: bubbled() });
        expect(q('.admin-help-list')?.hasAttribute('aria-busy')).toBe(false);
        expect(all('.admin-help-row')).toHaveLength(2);
    });

    it('says a thread is loading where it is opened while a reply to another is on its way, and reads it when the send ends, never showing the other', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Thanks');
        press(button(words.text('thread.send')));
        const send = lastPost()[2];

        press(button(words.text('back')));
        press(all('.admin-help-row')[0] ?? null);

        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(q('.admin-help-thread')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-help-thread [role="status"]')?.textContent).toBe(words.text('thread.loading'));
        expect(q('.admin-help-failed')).toBeNull();

        const replied: HelpThread = { ...INVOICE, messages: [...INVOICE.messages, { id: 2, side: 'business', name: 'Maria Owner', mine: true, body: 'Thanks', at: '2026-11-17T12:00:00Z' }] };
        land(send, { help_thread: replied, help: bubbled() });

        expect(inertia.post).toHaveBeenCalledTimes(3);
        expect(lastPost()[0]).toBe('/admin/help/aaaa-1/read');
        expect(q('.admin-help-message')).toBeNull();
        expect(q('.admin-help-thread')?.getAttribute('aria-busy')).toBe('true');

        land(lastPost()[2], { help_thread: SLOW, help: bubbled() });
        expect(q('.admin-help-thread h3')?.textContent).toBe('The calendar is slow');
        expect(all('.admin-help-message')).toHaveLength(2);
    });

    it('keeps a refusal that lands on the form while the list is shown, under its field when the form is next opened and gone the time after', () => {
        render();
        openList();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'A subject');
        typeInto(q('#admin-help-body'), 'A message');
        press(button(words.text('form.send')));
        const send = lastPost()[2];

        press(button(words.text('back')));
        expect(inertia.reload).toHaveBeenCalledTimes(2);

        refuse(send, refusal('ticket-limit-token', 'subject'));
        expect(inertia.reload).toHaveBeenCalledTimes(3);
        land(lastReload(), { help_tickets: ROWS, help: bubbled() });
        expect(all('.admin-help-row')).toHaveLength(2);

        press(button(words.text('open')));
        expect(q('#admin-help-subject-error')?.textContent).toBe('ticket-limit-token');
        expect(field('#admin-help-subject').value).toBe('A subject');

        press(button(words.text('back')));
        press(button(words.text('open')));
        expect(q('#admin-help-subject')?.getAttribute('aria-invalid')).not.toBe('true');
        expect(field('#admin-help-subject').value).toBe('A subject');
    });

    it('reads the thread again after a reply that ended with no answer, and empties the reply box where the read holds the reply as a message the business wrote since', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Any news? ');
        press(button(words.text('thread.send')));
        cancel(lastPost()[2]);

        expect(inertia.post).toHaveBeenCalledTimes(3);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/read');
        expect(field('#admin-help-reply').value).toBe('Any news? ');
        expect(q('.admin-toaster')?.textContent ?? '').toBe('');

        const stored: HelpThread = {
            ...INVOICE,
            messages: [
                ...INVOICE.messages,
                { id: 2, side: 'business', name: 'Maria Owner', mine: true, body: 'Any news?', at: '2026-11-17T12:00:00Z' },
                { id: 3, side: 'platform', name: 'Brand Support', mine: false, body: 'It is on its way.', at: '2026-11-17T12:05:00Z' },
            ],
        };
        land(lastPost()[2], { help_thread: stored, help: bubbled() });

        expect(all('.admin-help-message')).toHaveLength(3);
        expect(field('#admin-help-reply').value).toBe('');
    });

    it('keeps the reply where the read after a lost send does not hold it, said once as not done', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Any news?');
        press(button(words.text('thread.send')));
        lose(lastPost()[2]);

        expect(q('.admin-toaster')?.textContent).toContain(shell.text('toaster.not_done'));
        expect(inertia.post).toHaveBeenCalledTimes(3);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/read');

        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });

        expect(all('.admin-help-message')).toHaveLength(1);
        expect(field('#admin-help-reply').value).toBe('Any news?');

        press(button(words.text('thread.send')));
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/messages');
    });

    it('keeps the reply where the business had written the same words before and the read after a lost send holds nothing it wrote since, though the platform wrote them meanwhile', () => {
        const asked: HelpThread = { ...INVOICE, messages: [...INVOICE.messages, { id: 2, side: 'business', name: 'Maria Owner', mine: true, body: 'Any update?', at: '2026-11-17T12:00:00Z' }] };
        const echoed: HelpThread = { ...asked, messages: [...asked.messages, { id: 3, side: 'platform', name: 'Brand Support', mine: false, body: 'Any update?', at: '2026-11-17T12:05:00Z' }] };
        const stored: HelpThread = { ...echoed, messages: [...echoed.messages, { id: 4, side: 'business', name: 'Maria Owner', mine: true, body: 'Any update?', at: '2026-11-17T12:10:00Z' }] };

        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: asked, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Any update?');
        press(button(words.text('thread.send')));
        lose(lastPost()[2]);

        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/read');
        land(lastPost()[2], { help_thread: echoed, help: bubbled() });

        expect(all('.admin-help-message')).toHaveLength(3);
        expect(field('#admin-help-reply').value).toBe('Any update?');

        press(button(words.text('thread.send')));
        expect(inertia.post).toHaveBeenCalledTimes(4);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/messages');
        expect(lastPost()[1]).toEqual({ body: 'Any update?' });

        cancel(lastPost()[2]);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/read');
        land(lastPost()[2], { help_thread: stored, help: bubbled() });

        expect(all('.admin-help-message')).toHaveLength(4);
        expect(field('#admin-help-reply').value).toBe('');
    });

    it('holds Send, saying it is sending, while the thread is read after a reply that ended with no answer, and frees it when the read lands', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Any news?');
        press(button(words.text('thread.send')));
        cancel(lastPost()[2]);

        expect(inertia.post).toHaveBeenCalledTimes(3);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/read');
        expect(q('.admin-help-reply [aria-busy="true"]')?.textContent).toContain(words.text('thread.sending'));

        press(q('.admin-help-reply button[type="submit"]'));
        submit(q('.admin-help-reply'));
        expect(inertia.post).toHaveBeenCalledTimes(3);

        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        expect(q('.admin-help-reply [aria-busy="true"]')).toBeNull();
        expect(field('#admin-help-reply').value).toBe('Any news?');

        submit(q('.admin-help-reply'));
        expect(inertia.post).toHaveBeenCalledTimes(4);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/messages');
        expect(lastPost()[1]).toEqual({ body: 'Any news?' });
    });

    it('frees Send where that read fails, the reply kept, and Send posts the reply again', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Any news?');
        press(button(words.text('thread.send')));
        cancel(lastPost()[2]);
        lose(lastPost()[2]);

        expect(inertia.post).toHaveBeenCalledTimes(3);
        expect(q('.admin-help-failed')?.textContent).toContain(words.text('thread.failed'));
        expect(q('.admin-help-reply [aria-busy="true"]')).toBeNull();
        expect(field('#admin-help-reply').value).toBe('Any news?');

        press(button(words.text('thread.send')));
        expect(inertia.post).toHaveBeenCalledTimes(4);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/messages');
        expect(lastPost()[1]).toEqual({ body: 'Any news?' });
    });

    it('still has the reply to settle after that read fails: the next read holds Send again, and empties the reply box where it holds the reply', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Any news?');
        press(button(words.text('thread.send')));
        cancel(lastPost()[2]);
        cancel(lastPost()[2]);

        press(button(words.text('retry')));
        expect(inertia.post).toHaveBeenCalledTimes(4);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/read');
        expect(q('.admin-help-reply [aria-busy="true"]')?.textContent).toContain(words.text('thread.sending'));

        submit(q('.admin-help-reply'));
        expect(inertia.post).toHaveBeenCalledTimes(4);

        const stored: HelpThread = { ...INVOICE, messages: [...INVOICE.messages, { id: 2, side: 'business', name: 'Maria Owner', mine: true, body: 'Any news?', at: '2026-11-17T12:00:00Z' }] };
        land(lastPost()[2], { help_thread: stored, help: bubbled() });

        expect(all('.admin-help-message')).toHaveLength(2);
        expect(field('#admin-help-reply').value).toBe('');
        expect(q('.admin-help-reply [aria-busy="true"]')).toBeNull();
    });

    it('leaves a new ticket sent during that read saying it is sending when the read ends', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Any news?');
        press(button(words.text('thread.send')));
        cancel(lastPost()[2]);
        const read = lastPost()[2];

        chooseTab(words.text('tabs.answers'));
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'Two staff share a chair');
        typeInto(q('#admin-help-body'), 'How?');
        press(button(words.text('form.send')));

        expect(inertia.post).toHaveBeenCalledTimes(4);
        expect(lastPost()[0]).toBe('/admin/help');

        cancel(read);
        expect(q('.admin-help-form [aria-busy="true"]')?.textContent).toContain(words.text('form.sending'));
    });

    it('reads the thread the next time the panel is shown where a reply ended with no answer while it was closed', () => {
        render();
        openList();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Any news?');
        press(button(words.text('thread.send')));
        const send = lastPost()[2];
        press(button(words.text('close')));
        cancel(send);

        expect(inertia.post).toHaveBeenCalledTimes(2);

        press(bubble());
        expect(inertia.post).toHaveBeenCalledTimes(3);
        expect(lastPost()[0]).toBe('/admin/help/bbbb-2/read');
        expect(field('#admin-help-reply').value).toBe('Any news?');
        expect(q('.admin-help-reply [aria-busy="true"]')?.textContent).toContain(words.text('thread.sending'));

        const stored: HelpThread = { ...INVOICE, messages: [...INVOICE.messages, { id: 2, side: 'business', name: 'Maria Owner', mine: true, body: 'Any news?', at: '2026-11-17T12:00:00Z' }] };
        land(lastPost()[2], { help_thread: stored, help: bubbled() });

        expect(all('.admin-help-message')).toHaveLength(2);
        expect(field('#admin-help-reply').value).toBe('');
        expect(q('.admin-help-reply [aria-busy="true"]')).toBeNull();
    });

    it('shows the list and reads it again after a new ticket that ended with no answer, the form keeping what was typed', () => {
        render();
        openList();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'Two staff share a chair');
        typeInto(q('#admin-help-body'), 'How?');
        press(button(words.text('form.send')));
        cancel(lastPost()[2]);

        expect(q('.admin-help-form')).toBeNull();
        expect(q('.admin-help-list')?.getAttribute('aria-busy')).toBe('true');
        expect(inertia.reload).toHaveBeenCalledTimes(3);
        expect(lastReload().only).toEqual(['help_tickets', 'help']);
        expect(document.activeElement?.textContent).toBe(words.text('open'));

        const row: HelpTicketRow = { uuid: 'cccc-3', subject: 'Two staff share a chair', status: 'open', last_message_at: '2026-11-17T12:00:00Z', unread: false };
        land(lastReload(), { help_tickets: [row, ...ROWS], help: bubbled() });
        expect(all('.admin-help-row .truncate')[0]?.textContent).toBe('Two staff share a chair');

        press(button(words.text('open')));
        expect(field('#admin-help-subject').value).toBe('Two staff share a chair');
        expect(field('#admin-help-body').value).toBe('How?');
    });

    it('shows the list, read again, and not the form where a ticket is opened before a read of the list has landed after a new ticket that ended with no answer', () => {
        render();
        openList();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'Two staff share a chair');
        typeInto(q('#admin-help-body'), 'How?');
        press(button(words.text('form.send')));
        lose(lastPost()[2]);

        expect(inertia.reload).toHaveBeenCalledTimes(3);
        press(button(words.text('open')));

        expect(q('.admin-help-form')).toBeNull();
        expect(q('.admin-help-list')?.getAttribute('aria-busy')).toBe('true');
        expect(inertia.reload).toHaveBeenCalledTimes(4);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        cancel(lastReload());
        press(button(words.text('open')));

        expect(q('.admin-help-form')).toBeNull();
        expect(inertia.reload).toHaveBeenCalledTimes(5);

        land(lastReload(), { help_tickets: ROWS, help: bubbled() });
        press(button(words.text('open')));

        expect(field('#admin-help-subject').value).toBe('Two staff share a chair');
        expect(field('#admin-help-body').value).toBe('How?');
    });

    it('shows the list, read then, and not the form where a ticket is opened from the answers after a new ticket that ended with no answer while they were shown', () => {
        render();
        openList();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'Two staff share a chair');
        typeInto(q('#admin-help-body'), 'How?');
        press(button(words.text('form.send')));
        const send = lastPost()[2];
        chooseTab(words.text('tabs.answers'));
        cancel(send);

        expect(inertia.reload).toHaveBeenCalledTimes(2);
        press(button(words.text('open')));

        expect(q('[role="tab"][aria-selected="true"]')?.textContent).toBe(words.text('tabs.tickets'));
        expect(q('.admin-help-form')).toBeNull();
        expect(q('.admin-help-list')?.getAttribute('aria-busy')).toBe('true');
        expect(inertia.reload).toHaveBeenCalledTimes(3);
        expect(lastReload().only).toEqual(['help_tickets', 'help']);

        land(lastReload(), { help_tickets: ROWS, help: bubbled() });
        chooseTab(words.text('tabs.answers'));
        press(button(words.text('open')));

        expect(field('#admin-help-subject').value).toBe('Two staff share a chair');
        expect(field('#admin-help-body').value).toBe('How?');
        expect(inertia.post).toHaveBeenCalledTimes(1);
    });

    it('opens a thread on its newest message, the reply box brought into view then and each time a message is added, and never scrolls by moving focus', () => {
        const focus = vi.spyOn(HTMLElement.prototype, 'focus');

        try {
            render();
            openList();
            expect(focus).toHaveBeenLastCalledWith({ preventScroll: true });

            const body = q('[role="tabpanel"]:not([hidden])');
            press(all('.admin-help-row')[1] ?? null);

            expect(document.activeElement?.textContent).toBe(words.text('back'));
            expect(focus).toHaveBeenLastCalledWith({ preventScroll: true });
            expect(scrolls).toEqual([]);

            land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
            expect(scrolls).toEqual([{ element: q('.admin-help-reply'), options: { block: 'nearest' } }]);

            typeInto(q('#admin-help-reply'), 'More');
            press(button(words.text('thread.send')));
            const replied: HelpThread = { ...INVOICE, messages: [...INVOICE.messages, { id: 2, side: 'business', name: 'Maria Owner', mine: true, body: 'More', at: '2026-11-17T12:00:00Z' }] };
            land(lastPost()[2], { help_thread: replied, help: bubbled() });
            expect(scrolls).toHaveLength(2);
            expect(scrolls[1]?.element).toBe(q('.admin-help-reply'));

            if (body !== null) {
                body.scrollTop = 120;
            }

            press(button(words.text('back')));
            expect(body?.scrollTop).toBe(0);
            expect(document.activeElement?.textContent).toBe(words.text('open'));
            expect(focus).toHaveBeenLastCalledWith({ preventScroll: true });
            expect(scrolls).toHaveLength(2);
        } finally {
            focus.mockRestore();
        }
    });
});

describe('the help panel\'s place on the screen', () => {
    it('stands beside the page at the desktop width and closes on a press outside it, focus staying where the press put it', async () => {
        const outside = pageButton();
        render();
        openPanel();
        await settle();

        expect(panel()?.hasAttribute('aria-modal')).toBe(false);
        expect(panel()?.className).toContain('print:hidden');

        pressWhole(outside);
        expect(panel()).toBeNull();

        await settle();
        expect(document.activeElement).toBe(outside);
    });

    it('closes on a press on a control of the page that keeps its click to itself, focus staying on it', async () => {
        const quiet = pageButton();
        quiet.addEventListener('click', (event) => event.stopPropagation());
        render();
        openPanel();
        await settle();

        pressWhole(quiet);
        expect(panel()).toBeNull();

        await settle();
        expect(document.activeElement).toBe(quiet);
    });

    it('counts no click a key made as the click of a press outside that never got one: Send pressed by a key posts, the panel staying', async () => {
        const outside = pageButton();
        render();
        openList();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'Two staff share a chair');
        typeInto(q('#admin-help-body'), 'How?');
        await settle();

        act(() => outside.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, button: 0 })));
        press(button(words.text('form.send')));

        expect(panel()).not.toBeNull();
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(lastPost()[0]).toBe('/admin/help');

        await settle();
        expect(panel()).not.toBeNull();

        pressWhole(outside);
        expect(panel()).toBeNull();
    });

    it('forgets a press outside that the browser cancelled: a click that follows with no new press closes nothing', async () => {
        const quiet = pageButton();
        quiet.addEventListener('click', (event) => event.stopPropagation());
        render();
        openPanel();
        await settle();

        act(() => {
            quiet.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, button: 0 }));
            quiet.dispatchEvent(new PointerEvent('pointercancel', { bubbles: true }));
        });
        act(() => quiet.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 })));
        await settle();

        expect(panel()).not.toBeNull();
    });

    it('closes when focus leaves it at the desktop width, focus staying where it went', async () => {
        const outside = pageButton();
        render();
        openPanel();
        await settle();

        act(() => outside.focus());
        expect(panel()).toBeNull();

        await settle();
        expect(document.activeElement).toBe(outside);
    });

    it('hands focus back to the bubble when Close closes it, and when Escape does', async () => {
        render();
        openPanel();
        await settle();
        press(button(words.text('close')));
        await settle();

        expect(panel()).toBeNull();
        expect(document.activeElement).toBe(bubble());

        press(bubble());
        await settle();
        expect(document.activeElement?.getAttribute('aria-label')).toBe(words.text('search.label'));

        key(document.activeElement, 'Escape');
        await settle();
        expect(panel()).toBeNull();
        expect(document.activeElement).toBe(bubble());
    });

    it('closes on a press on the bubble and does not open again, focus on the bubble', async () => {
        render();
        openPanel();
        await settle();

        pressWhole(bubble());
        expect(panel()).toBeNull();

        await settle();
        expect(panel()).toBeNull();
        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(document.activeElement).toBe(bubble());
    });

    it('stays open on focus on a toast and on a press on one, though the press takes the toast out of the page', async () => {
        render();
        openList();
        await settle();
        press(button(words.text('open')));
        typeInto(q('#admin-help-subject'), 'A subject');
        typeInto(q('#admin-help-body'), 'A message');
        press(button(words.text('form.send')));
        refuse(lastPost()[2], refusal('demo-token'));

        const dismiss = q('.admin-toast-dismiss');
        act(() => (dismiss as HTMLElement | null)?.focus());
        expect(document.activeElement).toBe(dismiss);
        expect(panel()).not.toBeNull();

        pressWhole(dismiss);
        await settle();

        expect(dismiss?.isConnected).toBe(false);
        expect(panel()).not.toBeNull();
        expect(field('#admin-help-body').value).toBe('A message');
    });

    it('keeps a reply being typed across a close by a press outside', async () => {
        const outside = pageButton();
        render();
        openList();
        await settle();
        press(all('.admin-help-row')[1] ?? null);
        land(lastPost()[2], { help_thread: INVOICE, help: bubbled() });
        typeInto(q('#admin-help-reply'), 'Half a thought');

        pressWhole(outside);
        expect(panel()).toBeNull();

        press(bubble());
        expect(field('#admin-help-reply').value).toBe('Half a thought');
    });

    it('lets Tab walk out of it at either end at the desktop width', async () => {
        render();
        openPanel();
        await settle();
        const first = button(words.text('close'));
        const last = button(words.text('open'));

        act(() => (last as HTMLElement | null)?.focus());
        key(last, 'Tab');
        expect(document.activeElement).toBe(last);
        expect(panel()).not.toBeNull();

        act(() => (first as HTMLElement | null)?.focus());
        key(first, 'Tab', { shiftKey: true });
        expect(document.activeElement).toBe(first);
    });

    it('closes at the desktop width when a dialog opens over the screen, which keeps focus and its own Escape', async () => {
        const closed = vi.fn();
        render();
        openPanel();
        await settle();

        render(over(closed));
        await settle();

        expect(q('.admin-help-panel')).toBeNull();
        expect(q('.over-the-screen')?.contains(document.activeElement)).toBe(true);

        key(document.activeElement, 'Escape');
        expect(closed).toHaveBeenCalledWith(false);
    });

    it('fills the screen as a dialog below the breakpoint, nothing outside closing it and Tab kept inside it', async () => {
        undo = onPhone();
        render();
        openPanel();
        await settle();

        expect(panel()?.getAttribute('aria-modal')).toBe('true');
        expect(panel()?.className).toContain('inset-0');
        expect(panel()?.className).toContain('print:hidden');
        expect(q('.admin-help-backdrop')?.className).toContain('print:hidden');

        pressWhole(document.body);
        expect(panel()).not.toBeNull();

        const last = button(words.text('open'));
        act(() => (last as HTMLElement | null)?.focus());
        key(last, 'Tab');
        expect(document.activeElement).toBe(button(words.text('close')));
    });

    it('leaves Escape to a dialog open over it below the breakpoint, and closes on the next', async () => {
        const closed = vi.fn();
        undo = onPhone();
        render();
        openPanel();
        await settle();

        render(over(closed));
        await settle();
        key(document.activeElement, 'Escape');

        expect(closed).toHaveBeenCalledWith(false);
        expect(q('.admin-help-panel')).not.toBeNull();

        render();
        await settle();
        key(q('.admin-help-panel'), 'Escape');
        await settle();

        expect(q('.admin-help-panel')).toBeNull();
        expect(document.activeElement).toBe(bubble());
    });

    it('opens on the ticket a mail links to once no dialog is open over the screen, though an answer brought the props again without it meanwhile', async () => {
        const wizard = document.createElement('div');
        wizard.setAttribute('aria-modal', 'true');
        document.body.append(wizard);
        inertia.props = propsWith(bubbled({ open: 'aaaa-1' }));
        render();
        await settle();

        expect(inertia.reload).not.toHaveBeenCalled();

        inertia.props = propsWith(bubbled());
        render();
        await settle();

        expect(inertia.reload).not.toHaveBeenCalled();

        await act(async () => {
            wizard.remove();
            await Promise.resolve();
        });
        land(lastReload(), { help_panel: PANEL, help: bubbled() });

        expect(lastPost()[0]).toBe('/admin/help/aaaa-1/read');
        land(lastPost()[2], { help_thread: SLOW, help: bubbled() });
        expect(q('[role="tab"][aria-selected="true"]')?.textContent).toBe(words.text('tabs.tickets'));
        expect(q('.admin-help-thread h3')?.textContent).toBe('The calendar is slow');
    });
});
