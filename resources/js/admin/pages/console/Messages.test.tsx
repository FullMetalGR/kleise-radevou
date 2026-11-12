import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import { NOT_DONE } from '@/lib/visits';
import Messages, { LANGUAGE_WORDS, mailtoHref, type MessageRow, MESSAGES_LIST_ERROR, NO_ARCHIVED, NO_OPEN } from './Messages';

/*
 * The console's contact messages: each message as the server sent it, the address a mailto: link,
 * the language in words and the message whole with its line breaks kept; the view and the page as
 * partial reloads of the list, the query carrying them; Archive on each open message, busy on its
 * own message until the server's answer lands, one at a time, nothing optimistic, and a failure
 * the server could not word an error toast that stays. The framework is stood in for by a router
 * that records each visit and the failure listeners registered around it.
 */

const shell = keyedShell();

type Visit = { only?: string[]; replace?: boolean; preserveScroll?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    post: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: inertia.props }),
    router: {
        get: inertia.get,
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const MARIA: MessageRow = {
    id: 7,
    received: 'Monday 19 October 2026 at 11:15',
    name: 'Maria Papadopoulou',
    email: 'maria@salon.example',
    language: 'en',
    message: 'Do you take bookings for two chairs?',
};

const NIKOS: MessageRow = {
    id: 4,
    received: 'Monday 19 October 2026 at 09:00',
    name: 'Nikos Georgiou',
    email: 'nikos+leads@clinic.example',
    language: 'el',
    message: 'First line\nSecond line\r\n\r\nAfter a gap',
};

function propsWith(rows: MessageRow[], patch: { view?: 'open' | 'archived'; total?: number; page?: number } = {}): Record<string, unknown> {
    return {
        flash: null,
        operator: 'Pat Operator',
        errors: {},
        nav: [],
        zone: 'Europe/Athens',
        filters: { view: patch.view ?? 'open' },
        messages: { rows, total: patch.total ?? rows.length, page: patch.page ?? 1, page_size: 25 },
    };
}

let view: Mounted;

function show(rows: MessageRow[], patch: { view?: 'open' | 'archived'; total?: number; page?: number } = {}): void {
    inertia.props = propsWith(rows, patch);
    view.render(
        <ToastProvider>
            <Messages />
        </ToastProvider>,
    );
}

function q(selector: string): Element | null {
    return view.host.querySelector(selector);
}

function all(selector: string): Element[] {
    return [...view.host.querySelectorAll(selector)];
}

function texts(selector: string): (string | null)[] {
    return all(selector).map((element) => element.textContent);
}

function lastRead(): [string, Record<string, string>, Visit] {
    const call = inertia.get.mock.lastCall;

    if (call === undefined) {
        throw new Error('No read was made.');
    }

    return call as [string, Record<string, string>, Visit];
}

function lastPost(): [string, Record<string, unknown>, Visit] {
    const call = inertia.post.mock.lastCall;

    if (call === undefined) {
        throw new Error('No write was made.');
    }

    return call as [string, Record<string, unknown>, Visit];
}

/** The view's option of the label given. */
function option(label: string): Element | null {
    return all('.admin-messages-view .admin-segmented__option').find((button) => button.textContent === label) ?? null;
}

beforeEach(() => {
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('the messages screen', () => {
    it('renders each message as the server sent it: the instant, the sender, the address as a mailto link, the language in words and the message, N of M in the head', () => {
        show([MARIA, NIKOS], { total: 30 });

        expect(q('h1')?.textContent).toBe('Messages');
        expect(texts('.admin-message__received')).toEqual([`Received ${MARIA.received}`, `Received ${NIKOS.received}`]);
        expect(texts('.admin-message__name')).toEqual(['Maria Papadopoulou', 'Nikos Georgiou']);
        expect(all('.admin-message__email').map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
            ['maria@salon.example', 'mailto:maria@salon.example'],
            ['nikos+leads@clinic.example', 'mailto:nikos+leads@clinic.example'],
        ]);
        expect(texts('.admin-message__language')).toEqual(['Sent in English', 'Sent in Greek']);
        expect(q('.admin-message__body')?.textContent).toBe(MARIA.message);
        expect(q('.admin-messages__count')?.textContent).toBe('2 of 30');
        expect(q('.admin-pagination')?.textContent).toContain(shell.text('kit.pagination.page', { page: '1', last: '2' }));
    });

    it('keeps the message\'s line breaks, each one a break whatever the sender\'s machine wrote', () => {
        show([NIKOS]);

        const body = q('.admin-message__body');

        expect(body?.querySelectorAll('br')).toHaveLength(3);
        expect([...(body?.childNodes ?? [])].map((node) => (node.nodeName === 'BR' ? '|' : node.textContent))).toEqual(['First line', '|', 'Second line', '|', '|', 'After a gap']);
    });

    it('words both languages the form is sent in', () => {
        expect(LANGUAGE_WORDS).toEqual({ en: 'English', el: 'Greek' });
    });

    it('writes the address into the mailto link so nothing in it can add a header', () => {
        expect(mailtoHref('pat@inbox.example')).toBe('mailto:pat@inbox.example');
        expect(mailtoHref('what?cc=all&subject=x@clinic.example')).toBe('mailto:what%3Fcc%3Dall%26subject%3Dx@clinic.example');
    });

    it('offers Archive on each open message and none on the archived view, which is the chosen option', () => {
        show([MARIA, NIKOS]);

        expect(texts('.admin-message__archive')).toEqual(['Archive', 'Archive']);
        expect(option('Open')?.getAttribute('aria-pressed')).toBe('true');
        expect(q('.admin-messages h2')?.textContent).toBe('Open messages');

        show([MARIA], { view: 'archived' });

        expect(q('.admin-message__archive')).toBeNull();
        expect(option('Archived')?.getAttribute('aria-pressed')).toBe('true');
        expect(q('.admin-messages h2')?.textContent).toBe('Archived messages');
    });

    it('reads the archived view as a partial reload of the list on its first page, and the open view with the default left out', () => {
        show([MARIA], { page: 2, total: 40 });

        press(option('Archived'));

        const [path, query, visit] = lastRead();

        expect(path).toBe('/messages');
        expect(query).toEqual({ view: 'archived' });
        expect(visit.only).toEqual(['messages', 'filters']);
        expect(visit.replace).toBe(true);

        show([MARIA], { view: 'archived' });
        press(option('Open'));

        expect(lastRead()[1]).toEqual({});
    });

    it('reads the next page with the view kept in the query', () => {
        show([MARIA], { view: 'archived', total: 60 });

        press(q('.admin-pagination__next'));

        expect(lastRead()[0]).toBe('/messages');
        expect(lastRead()[1]).toEqual({ view: 'archived', page: '2' });
    });

    it('posts no body to the message\'s archive, busy on that message until the answer lands, one at a time, and keeps the list as it was', () => {
        show([MARIA, NIKOS]);
        const archives = () => all('.admin-message__archive');
        press(archives()[1] ?? null);

        const [path, body, visit] = lastPost();

        expect(path).toBe('/messages/4/archive');
        expect(body).toEqual({});
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(archives().map((button) => button.textContent)).toEqual(['Archive', 'Archiving']);
        expect(archives()[1]?.getAttribute('aria-busy')).toBe('true');
        expect(archives()[1]?.getAttribute('aria-describedby')).toBe('message-4-from');
        expect(q('#message-4-from')?.textContent).toContain('Nikos Georgiou');

        press(archives()[0] ?? null);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(archives().map((button) => button.textContent)).toEqual(['Archive', 'Archive']);
        expect(texts('.admin-message__name')).toEqual(['Maria Papadopoulou', 'Nikos Georgiou']);
    });

    it('carries the screen\'s page in the archive\'s own query, the defaults left out, so the answer comes back to it', () => {
        show([MARIA], { total: 60, page: 2 });
        press(q('.admin-message__archive'));

        expect(lastPost()[0]).toBe('/messages/7/archive?page=2');
        expect(lastPost()[1]).toEqual({});
    });

    it('says a failure the server could not word in an error toast that stays', () => {
        show([MARIA]);
        press(q('.admin-message__archive'));

        const [, , visit] = lastPost();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect([...document.body.querySelectorAll('.admin-toast[data-kind="error"] p')].map((p) => p.textContent)).toEqual([shell.text(NOT_DONE)]);
    });

    it('shows the empty panel once the list has settled empty, in words for each view', () => {
        show([]);

        expect(q('.admin-messages-empty')?.textContent).toBe(NO_OPEN);
        expect(q('.admin-messages')).toBeNull();

        show([], { view: 'archived' });

        expect(q('.admin-messages-empty')?.textContent).toBe(NO_ARCHIVED);
    });

    it('says a failed read in the screen\'s own words above the messages it keeps, and shows no empty panel', () => {
        show([MARIA], { total: 60 });

        press(q('.admin-pagination__next'));

        const [, , visit] = lastRead();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('invalid')?.(new Event('invalid', { cancelable: true })));
        act(() => visit.onFinish?.());

        expect(q('.admin-list-error')?.textContent).toBe(MESSAGES_LIST_ERROR);
        expect(texts('.admin-message__name')).toEqual(['Maria Papadopoulou']);
        expect(q('.admin-messages-empty')).toBeNull();
    });
});
