import { useEffect, useRef, useState } from 'react';
import type { ShellWords } from '@/lib/strings';
import { type Failure, NOT_DONE } from '@/lib/visits';
import { ask, HELP_URL } from './requests';
import type { HelpField, HelpThread, HelpTicketRow } from './types';
import type { HelpWords } from './words';

/*
 * The Tickets tab's state and its four requests. The tab shows one of three views: the list, the
 * form for a new ticket, or one ticket's thread. The list is read each time it is shown, a thread
 * each time it is opened and again after a reply; nothing is read on a timer.
 *
 * A thread comes back as the flash of the write that asked for it, which lasts one request: a
 * second tab, or two presses close together, can take it first. So a thread is shown only when it
 * is the ticket asked for. A thread read that comes back with nothing, or with another ticket, is
 * asked once more and then gives way to the list; a new ticket whose thread does not come back
 * shows the list, which came with the same answer and holds the new ticket first. No thread is
 * ever shown empty or out of date.
 *
 * One write at a time: while a ticket or a reply is being sent, no read starts, since a read's
 * request would cancel the write's. A read asked for meanwhile waits: its view says it is loading,
 * and what is shown is read when the write ends, unless the write's own answer brought it. A read
 * the write's request cancelled is forgotten rather than left loading. Every request's ending
 * clears its own loading state, so a request cancelled by a visit elsewhere leaves a line that
 * offers to try again instead of a panel that waits.
 *
 * A write can end with no answer at all: cancelled by a visit elsewhere, or lost with the
 * connection, and the server may have stored it all the same. So what would show it is read
 * again, at once where the tab is on screen and otherwise the next time it is shown: the thread
 * after a reply, whose draft goes if the read holds it as a message the business wrote since the
 * send began, so the same message is not sent twice, and whose Send waits while that read runs;
 * the list after a new ticket, shown in the form's place, the form keeping what was typed for the
 * next time it is opened, which is not before a read of the list has landed.
 */

/** The longest subject and message the server takes; the fields stop there as they are typed. */
export const LONGEST_SUBJECT = 150;

export const LONGEST_MESSAGE = 5000;

type TicketsView = { kind: 'list' } | { kind: 'form' } | { kind: 'thread'; uuid: string };

export type ListState = { rows: HelpTicketRow[] | null; loading: boolean; failed: boolean };

type ThreadState = { uuid: string | null; value: HelpThread | null; loading: boolean; failed: boolean };

type Errors = Record<HelpField, string | null>;

type FormState = { subject: string; body: string; errors: Errors };

export type Tickets = {
    view: TicketsView;
    /** Counts the moves from one view to another, so the panel can move focus into the view shown. */
    moved: number;
    list: ListState;
    thread: ThreadState;
    form: FormState;
    /** The reply being written in the thread shown. */
    reply: string;
    replyError: string | null;
    /** Counts refused sends, the server's and the panel's own checks alike, so focus moves to the field once per refusal. */
    refusal: number;
    /** What is being sent. A reply that ended with no answer is still being sent while the read that says whether it was stored runs. */
    writing: 'ticket' | 'reply' | null;
    /**
     * Shows a view and reads what it shows, the list or a thread; focus moves into it unless the
     * tab or the panel is what was opened, where focus already has its place.
     */
    enter: (view: TicketsView, focus?: boolean) => void;
    showList: () => void;
    /**
     * Shows the form, its message prefilled with what was searched for unless a draft is there.
     * After a new ticket that ended with no answer it shows the list instead, and reads it, until
     * a read of the list has landed.
     */
    showForm: (prefill: string) => void;
    showThread: (uuid: string) => void;
    retry: () => void;
    typeSubject: (value: string) => void;
    typeBody: (value: string) => void;
    typeReply: (value: string) => void;
    sendTicket: () => void;
    sendReply: () => void;
};

const LIST: TicketsView = { kind: 'list' };

const NO_ERRORS: Errors = { subject: null, body: null };

const EMPTY_FORM: FormState = { subject: '', body: '', errors: NO_ERRORS };

const NO_THREAD: ThreadState = { uuid: null, value: null, loading: false, failed: false };

/** A thread as it is shown once read. */
function shownThread(value: HelpThread): ThreadState {
    return { uuid: value.uuid, value, loading: false, failed: false };
}

/** The field a refusal names, where it names one of the panel's two. */
function fieldOf(failure: Failure): HelpField | null {
    return failure.field === 'subject' || failure.field === 'body' ? failure.field : null;
}

/** A record without one key. */
function without(record: Record<string, string>, key: string): Record<string, string> {
    return Object.fromEntries(Object.entries(record).filter(([name]) => name !== key));
}

/** What the business wrote in a thread, message by message, oldest first. */
function ofBusiness(thread: HelpThread): string[] {
    return thread.messages.filter((message) => message.side === 'business').map((message) => message.body);
}

/** Whether a failed write got an answer from the server; a request lost on the way got none. */
function answeredBy(failure: Failure): boolean {
    return failure.status !== null;
}

/**
 * onScreen: whether the tab is before the user now, the panel open on it. A read owed after a
 * write runs at once only then; otherwise the next showing reads.
 */
export function useTickets(words: HelpWords | null, shell: ShellWords, toast: (message: string) => void, onScreen: boolean): Tickets {
    const [view, setView] = useState<TicketsView>(LIST);
    const [moved, setMoved] = useState(0);
    const [list, setList] = useState<ListState>({ rows: null, loading: false, failed: false });
    const [thread, setThread] = useState<ThreadState>(NO_THREAD);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [replies, setReplies] = useState<Record<string, string>>({});
    const [replyError, setReplyError] = useState<string | null>(null);
    const [refusal, setRefusal] = useState(0);
    const [writing, setWriting] = useState<'ticket' | 'reply' | null>(null);
    const shown = useRef<TicketsView>(LIST);
    const busy = useRef(false);
    const reads = useRef({ list: 0, thread: 0 });
    const seen = useRef(onScreen);
    /** Whether what is shown is to be read once the write in flight has ended. */
    const owed = useRef(false);
    /**
     * The tickets whose reply ended with no answer, each with how many messages the business had in
     * the thread as it was shown when that send began: the next read of each says whether the reply
     * was stored.
     */
    const unsure = useRef(new Map<string, number>());
    /** The thread read under way that says so, by its number: the reply's Send waits for its ending. */
    const settling = useRef<number | null>(null);
    /** Whether a new ticket's send ended with no answer and the list, which would show it, has not been read since. */
    const unseen = useRef(false);
    /** Whether a refusal landed on the form while another view was shown. */
    const late = useRef(false);

    useEffect(() => {
        seen.current = onScreen;
    });

    const go = (next: TicketsView, focus = true) => {
        shown.current = next;
        setView(next);

        if (focus) {
            setMoved((count) => count + 1);
        }
    };

    const showing = (uuid: string) => shown.current.kind === 'thread' && shown.current.uuid === uuid;

    const sentenceOf = (failure: Failure) => failure.sentence ?? shell.text(NOT_DONE);

    const readList = () => {
        if (busy.current) {
            owed.current = true;
            setList((now) => ({ rows: now.rows, loading: true, failed: false }));

            return;
        }

        const id = ++reads.current.list;
        let read = false;
        setList((now) => ({ rows: now.rows, loading: true, failed: false }));
        ask(
            {
                to: null,
                only: ['help_tickets', 'help'],
                landed: (props) => {
                    read = true;

                    if (id === reads.current.list) {
                        unseen.current = false;
                        setList({ rows: props.help_tickets ?? [], loading: false, failed: false });
                    }
                },
                refused: () => undefined,
                ended: () => {
                    if (!read && id === reads.current.list) {
                        setList((now) => ({ rows: now.rows, loading: false, failed: true }));
                    }
                },
            },
            shell,
        );
    };

    /** A thread read that brought back nothing, or another ticket: asked once more, then the list. */
    const missed = (id: number, uuid: string, last: boolean) => {
        if (id !== reads.current.thread || !showing(uuid)) {
            return;
        }

        if (!last) {
            readThread(uuid, true);

            return;
        }

        setThread(NO_THREAD);
        go(LIST);
        readList();
    };

    /**
     * A thread just read. Where its ticket's last reply ended with no answer, this read says
     * whether the reply was stored: the draft goes if the business wrote the same since that send
     * began. A thread only grows, at its end, so what the business wrote since is what it wrote
     * past the number of its messages then. The same words in a message it had already written
     * prove nothing, and the platform's messages are not counted, so one that arrived meanwhile
     * changes nothing.
     */
    const settle = (value: HelpThread) => {
        const known = unsure.current.get(value.uuid);

        if (known === undefined) {
            return;
        }

        unsure.current.delete(value.uuid);
        const since = ofBusiness(value).slice(known).map((body) => body.trim());
        setReplies((drafts) => (since.includes((drafts[value.uuid] ?? '').trim()) ? without(drafts, value.uuid) : drafts));
    };

    const readThread = (uuid: string, last: boolean) => {
        const waiting = (now: ThreadState): ThreadState => ({ uuid, value: now.uuid === uuid ? now.value : null, loading: true, failed: false });

        if (busy.current) {
            owed.current = true;
            setThread(waiting);

            return;
        }

        const id = ++reads.current.thread;
        let read = false;
        setThread(waiting);

        // A second press while this read runs would store the reply twice, so its Send waits.
        if (unsure.current.has(uuid)) {
            settling.current = id;
            setWriting('reply');
        }

        ask(
            {
                to: `${HELP_URL}/${encodeURIComponent(uuid)}/read`,
                only: ['help_thread', 'help'],
                landed: (props, then) => {
                    read = true;
                    const value = props.help_thread ?? null;

                    if (id !== reads.current.thread) {
                        return;
                    }

                    if (value !== null && value.uuid === uuid) {
                        setThread(shownThread(value));
                        settle(value);
                    } else {
                        then(() => missed(id, uuid, last));
                    }
                },
                refused: () => undefined,
                ended: () => {
                    if (!read && id === reads.current.thread) {
                        setThread((now) => ({ ...now, loading: false, failed: true }));
                    }

                    if (settling.current === id) {
                        settling.current = null;

                        // A write begun meanwhile says it is sending, and stops saying so at its own ending.
                        if (!busy.current) {
                            setWriting(null);
                        }
                    }
                },
            },
            shell,
        );
    };

    /** Reads what a view shows, where it shows something read: the list or a thread. */
    const read = (next: TicketsView) => {
        if (next.kind === 'list') {
            readList();
        } else if (next.kind === 'thread') {
            readThread(next.uuid, false);
        }
    };

    const enter = (next: TicketsView, focus = true) => {
        go(next, focus);
        read(next);
    };

    const startWrite = (kind: 'ticket' | 'reply') => {
        busy.current = true;
        owed.current = false;
        reads.current.list++;
        reads.current.thread++;
        setList((now) => ({ ...now, loading: false }));
        setThread((now) => ({ ...now, loading: false }));
        setWriting(kind);
    };

    /**
     * A write's ending, whichever way it ended. No read is in flight then, so a view left saying
     * it loads is waiting on a read this write held back: it is read now where the tab is on
     * screen, and otherwise stops saying so, the next showing reading it.
     */
    const endWrite = () => {
        const due = owed.current && seen.current;
        busy.current = false;
        owed.current = false;
        setWriting(null);
        setList((now) => ({ ...now, loading: false }));
        setThread((now) => ({ ...now, loading: false }));

        if (due) {
            read(shown.current);
        }
    };

    const refuseTicket = (failure: Failure) => {
        const field = fieldOf(failure);

        if (field === null) {
            toast(sentenceOf(failure));

            return;
        }

        late.current = shown.current.kind !== 'form';
        setForm((now) => ({ ...now, errors: { ...now.errors, [field]: sentenceOf(failure) } }));
        setRefusal((count) => count + 1);
    };

    /**
     * A new ticket's send that ended with no answer: the list takes the form's place and is read
     * again, since it would show the ticket if the server stored it.
     */
    const unsureTicket = () => {
        unseen.current = true;

        if (shown.current.kind === 'form') {
            go(LIST, seen.current);
        }

        if (shown.current.kind === 'list') {
            owed.current = true;
        }
    };

    /**
     * A reply's send that ended with no answer: its thread is read again, now if it is the one
     * shown. known: how many messages the business had in the thread when the send began.
     */
    const unsureReply = (uuid: string, known: number) => {
        unsure.current.set(uuid, known);

        if (showing(uuid)) {
            owed.current = true;
        }
    };

    const sendTicket = () => {
        if (busy.current || words === null) {
            return;
        }

        const subject = form.subject.trim();
        const body = form.body.trim();
        const errors: Errors = {
            subject: subject === '' ? words.choice('validation.subject', LONGEST_SUBJECT) : null,
            body: body === '' ? words.choice('validation.body', LONGEST_MESSAGE) : null,
        };

        if (errors.subject !== null || errors.body !== null) {
            setForm((now) => ({ ...now, errors }));
            setRefusal((count) => count + 1);

            return;
        }

        let answered = false;
        startWrite('ticket');
        ask(
            {
                to: HELP_URL,
                data: { subject, body },
                only: ['help_thread', 'help_tickets', 'help'],
                landed: (props) => {
                    const rows = props.help_tickets ?? null;
                    const value = props.help_thread ?? null;
                    answered = true;
                    late.current = false;
                    setForm(EMPTY_FORM);

                    if (rows !== null) {
                        setList({ rows, loading: false, failed: false });
                    }

                    if (value !== null && value.subject === subject) {
                        setReplyError(null);
                        setThread(shownThread(value));
                        go({ kind: 'thread', uuid: value.uuid });
                        owed.current = false;

                        return;
                    }

                    go(LIST);
                    owed.current = rows === null;
                },
                refused: (failure) => {
                    answered = answeredBy(failure);
                    refuseTicket(failure);
                },
                ended: () => {
                    if (!answered) {
                        unsureTicket();
                    }

                    endWrite();
                },
            },
            shell,
        );
    };

    const refuseReply = (failure: Failure) => {
        if (fieldOf(failure) === null) {
            toast(sentenceOf(failure));

            return;
        }

        setReplyError(sentenceOf(failure));
        setRefusal((count) => count + 1);
    };

    const sendReply = () => {
        const now = shown.current;

        // The reply box stands only under a thread that was read, so a send always has one to count from.
        if (busy.current || settling.current !== null || words === null || now.kind !== 'thread' || thread.uuid !== now.uuid || thread.value === null) {
            return;
        }

        const uuid = now.uuid;
        const known = ofBusiness(thread.value).length;
        const body = (replies[uuid] ?? '').trim();

        if (body === '') {
            setReplyError(words.choice('validation.body', LONGEST_MESSAGE));
            setRefusal((count) => count + 1);

            return;
        }

        let answered = false;
        startWrite('reply');
        ask(
            {
                to: `${HELP_URL}/${encodeURIComponent(uuid)}/messages`,
                data: { body },
                only: ['help_thread', 'help'],
                landed: (props, then) => {
                    const value = props.help_thread ?? null;
                    const other = shown.current.kind === 'thread' && shown.current.uuid !== uuid;
                    answered = true;
                    unsure.current.delete(uuid);
                    setReplies((drafts) => without(drafts, uuid));
                    setReplyError(null);

                    if (value === null || value.uuid !== uuid) {
                        then(() => {
                            if (showing(uuid)) {
                                readThread(uuid, true);
                            }
                        });

                        return;
                    }

                    // Another thread opened meanwhile keeps the view; it is read when this send ends.
                    if (!other) {
                        setThread(shownThread(value));
                    }

                    if (showing(uuid)) {
                        owed.current = false;
                    }
                },
                refused: (failure) => {
                    answered = answeredBy(failure);
                    refuseReply(failure);
                },
                ended: () => {
                    if (!answered) {
                        unsureReply(uuid, known);
                    }

                    endWrite();
                },
            },
            shell,
        );
    };

    const showThread = (uuid: string) => {
        setReplyError(null);
        enter({ kind: 'thread', uuid });
    };

    /** A refusal that landed on the form while it was not shown is still under its field; any other is from an earlier showing and goes. */
    const showForm = (prefill: string) => {
        // The form still holds a ticket that may have been stored: the list, which would show it, comes first.
        if (unseen.current) {
            enter(LIST);

            return;
        }

        const kept = late.current;
        late.current = false;
        setForm((now) => ({ ...now, body: now.body === '' ? prefill.trim() : now.body, errors: kept ? now.errors : NO_ERRORS }));
        go({ kind: 'form' });
    };

    const shownUuid = view.kind === 'thread' ? view.uuid : null;

    return {
        view,
        moved,
        list,
        thread,
        form,
        reply: shownUuid === null ? '' : (replies[shownUuid] ?? ''),
        replyError,
        refusal,
        writing,
        enter,
        showList: () => enter(LIST),
        showForm,
        showThread,
        retry: () => enter(shown.current),
        typeSubject: (value) => setForm((now) => ({ ...now, subject: value, errors: { ...now.errors, subject: null } })),
        typeBody: (value) => setForm((now) => ({ ...now, body: value, errors: { ...now.errors, body: null } })),
        typeReply: (value) => {
            if (shownUuid !== null) {
                setReplies((drafts) => ({ ...drafts, [shownUuid]: value }));
                setReplyError(null);
            }
        },
        sendTicket,
        sendReply,
    };
}
