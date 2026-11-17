import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import { NOT_DONE } from '@/lib/visits';
import Ticket, { CLOSE, REOPEN, REPLY_HELP, SEND, type ThreadMessage } from './Ticket';
import type { TicketSummary } from './Tickets';

/*
 * One help ticket on the console: the subject, the business linked to its console page, the status
 * with the Awaiting reply marker, and the messages oldest first under their writers' names, plain
 * text with their line breaks kept and nothing linked; the reply box posting the reply as the
 * operator, its refusal under the box with focus moved to the box, and the box emptied once the
 * reply is taken; Close on an open ticket and Reopen on a closed one, each busy until the answer
 * lands, one press at a time.
 */

const shell = keyedShell();

type Visit = { preserveScroll?: boolean; onStart?: () => void; onSuccess?: () => void; onError?: (errors: Record<string, string>) => void; onFinish?: () => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    post: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, className, children }: { href: string; className?: string; children?: unknown }) => (
        <a href={href} className={className}>
            {children as string}
        </a>
    ),
    usePage: () => ({ props: inertia.props }),
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const OPEN: TicketSummary = {
    id: 12,
    subject: 'Moving a booking',
    tenant: { name: 'Luna Hair Studio', url: '/tenants/luna' },
    status: 'open',
    awaiting: true,
    last_message: 'Monday 16 November 2026 at 12:00',
};

const MESSAGES: ThreadMessage[] = [
    { id: 1, side: 'business', name: 'Eleni Owner', at: 'Monday 16 November 2026 at 10:00', body: 'How do I move a booking?\r\nThanks.\n\nEleni' },
    { id: 2, side: 'platform', name: 'Pat Operator', at: 'Monday 16 November 2026 at 11:00', body: 'Drag it on the calendar.' },
    { id: 3, side: 'business', name: 'Eleni Owner', at: 'Monday 16 November 2026 at 12:00', body: '<b>Done</b>, see https://example.com' },
];

let view: Mounted;

function show(ticket: TicketSummary = OPEN, messages: ThreadMessage[] = MESSAGES): void {
    inertia.props = { flash: null, operator: 'Pat Operator', errors: {}, nav: [], zone: 'Europe/Athens', ticket, messages };
    view.render(
        <ToastProvider>
            <Ticket />
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

function lastPost(): [string, Record<string, unknown>, Visit] {
    const call = inertia.post.mock.lastCall;

    if (call === undefined) {
        throw new Error('No write was made.');
    }

    return call as [string, Record<string, unknown>, Visit];
}

function typeReply(text: string): void {
    const box = q('.admin-ticket-reply__body');

    if (!(box instanceof HTMLTextAreaElement)) {
        throw new Error('No reply box.');
    }

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set?.call(box, text);
        box.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function replyBox(): HTMLTextAreaElement {
    const box = q('.admin-ticket-reply__body');

    if (!(box instanceof HTMLTextAreaElement)) {
        throw new Error('No reply box.');
    }

    return box;
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('a help ticket\'s thread', () => {
    it('heads the thread with the subject, the business linked to its console page, the status with its marker and the newest instant', () => {
        show();

        expect(q('h1')?.textContent).toBe('Moving a booking');
        expect([q('.admin-ticket__tenant')?.textContent, q('.admin-ticket__tenant')?.getAttribute('href')]).toEqual(['Luna Hair Studio', '/tenants/luna']);
        expect(q('.admin-ticket__status')?.textContent).toBe('Open');
        expect(q('.admin-ticket__awaiting')?.textContent).toBe('Awaiting reply');
        expect(q('.admin-ticket__last')?.textContent).toBe('Last message Monday 16 November 2026 at 12:00');
        expect(q('.admin-ticket-back')?.getAttribute('href')).toBe('/tickets');
    });

    it('lists the messages oldest first under their writers\' names, the side and the instant beside each', () => {
        show();

        expect(texts('.admin-ticket-message__name')).toEqual(['Eleni Owner', 'Pat Operator', 'Eleni Owner']);
        expect(texts('.admin-ticket-message__side')).toEqual(['Business', 'Platform', 'Business']);
        expect(texts('.admin-ticket-message__at')).toEqual(MESSAGES.map((message) => message.at));
        expect(all('.admin-ticket-message').map((item) => item.getAttribute('data-side'))).toEqual(['business', 'platform', 'business']);
    });

    it('keeps a message\'s line breaks, each one a break whatever the writer\'s machine wrote', () => {
        show();

        const body = q('.admin-ticket-message__body');

        expect([...(body?.childNodes ?? [])].map((node) => (node.nodeName === 'BR' ? '|' : node.textContent))).toEqual(['How do I move a booking?', '|', 'Thanks.', '|', '|', 'Eleni']);
    });

    it('shows a message as plain text: no markup read and nothing turned into a link', () => {
        show();

        const body = all('.admin-ticket-message__body')[2];

        expect(body?.textContent).toBe('<b>Done</b>, see https://example.com');
        expect(body?.querySelector('b')).toBeNull();
        expect(body?.querySelector('a')).toBeNull();
    });

    it('marks no ticket as awaiting a reply when the server says it is not', () => {
        show({ ...OPEN, awaiting: false });

        expect(q('.admin-ticket__awaiting')).toBeNull();
    });

    it('posts the reply as the body, busy until the answer lands, and empties the box once it is taken', () => {
        show();
        typeReply('Drag it on the calendar.\n\nPat');

        expect(q('.admin-ticket-reply')?.textContent).toContain(REPLY_HELP);
        expect(replyBox().hasAttribute('maxlength')).toBe(false);

        press(q('.admin-ticket-reply__send'));

        const [path, body, visit] = lastPost();

        expect(path).toBe('/tickets/12/messages');
        expect(body).toEqual({ body: 'Drag it on the calendar.\n\nPat' });
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(q('.admin-ticket-reply__send')?.textContent).toBe('Sending');
        expect(q('.admin-ticket-reply__send')?.getAttribute('aria-busy')).toBe('true');

        press(q('.admin-ticket-reply__send'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onSuccess?.());
        act(() => visit.onFinish?.());

        expect(replyBox().value).toBe('');
        expect(q('.admin-ticket-reply__send')?.textContent).toBe(SEND);
    });

    it('shows the server\'s refusal under the box, keeps what was written and moves focus to the box', () => {
        show();
        typeReply('x');
        press(q('.admin-ticket-reply__send'));

        const [, , visit] = lastPost();

        act(() => visit.onStart?.());

        expect(document.activeElement).not.toBe(replyBox());

        act(() => visit.onError?.({ body: 'Keep the reply to 5,000 characters or fewer.' }));
        act(() => visit.onFinish?.());

        expect(q('#ticket-reply-error')?.textContent).toBe('Keep the reply to 5,000 characters or fewer.');
        expect(replyBox().getAttribute('aria-describedby')).toContain('ticket-reply-error');
        expect(replyBox().getAttribute('aria-invalid')).toBe('true');
        expect(replyBox().value).toBe('x');
        expect(document.activeElement).toBe(replyBox());
    });

    it('moves focus back to the box when the same refusal comes twice running', () => {
        show();
        press(q('.admin-ticket-reply__send'));
        act(() => lastPost()[2].onError?.({ body: 'Write the reply before sending it.' }));

        expect(document.activeElement).toBe(replyBox());

        act(() => replyBox().blur());
        press(q('.admin-ticket-reply__send'));

        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(q('#ticket-reply-error')).toBeNull();
        expect(document.activeElement).not.toBe(replyBox());

        act(() => lastPost()[2].onError?.({ body: 'Write the reply before sending it.' }));

        expect(q('#ticket-reply-error')?.textContent).toBe('Write the reply before sending it.');
        expect(document.activeElement).toBe(replyBox());
    });

    it('offers Close on an open ticket, posting no body, busy until the answer lands', () => {
        show();

        expect(q('.admin-ticket-reopen')).toBeNull();
        expect(q('.admin-ticket-close')?.textContent).toBe(CLOSE);

        press(q('.admin-ticket-close'));

        const [path, body, visit] = lastPost();

        expect(path).toBe('/tickets/12/close');
        expect(body).toEqual({});

        act(() => visit.onStart?.());

        expect(q('.admin-ticket-close')?.textContent).toBe('Closing');

        press(q('.admin-ticket-reply__send'));
        expect(inertia.post).toHaveBeenCalledTimes(1);
    });

    it('offers Reopen on a closed ticket, posting no body', () => {
        show({ ...OPEN, status: 'closed', awaiting: false });

        expect(q('.admin-ticket-close')).toBeNull();
        expect(q('.admin-ticket__status')?.textContent).toBe('Closed');
        expect(q('.admin-ticket-reopen')?.textContent).toBe(REOPEN);

        press(q('.admin-ticket-reopen'));

        expect(lastPost()[0]).toBe('/tickets/12/reopen');
        expect(lastPost()[1]).toEqual({});

        act(() => lastPost()[2].onStart?.());

        expect(q('.admin-ticket-reopen')?.textContent).toBe('Reopening');
    });

    it('says a failure the server could not word in an error toast that stays', () => {
        show();
        press(q('.admin-ticket-close'));

        const [, , visit] = lastPost();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect([...document.body.querySelectorAll('.admin-toast[data-kind="error"] p')].map((p) => p.textContent)).toEqual([shell.text(NOT_DONE)]);
    });
});
