import { type FormEvent, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Field, HelpLine, useFocusFirstFailing } from '@/components/ui/field';
import { Textarea, TextInput } from '@/components/ui/input';
import { formatInstant, type TenantClock } from '@/lib/format';
import { cn } from '@/lib/utils';
import { LONGEST_MESSAGE, LONGEST_SUBJECT, type ListState, type Tickets } from './tickets';
import type { HelpMessage, HelpThread } from './types';
import type { HelpWords } from './words';

/*
 * The Tickets tab's three views. The list: a button that opens a ticket, then the business's
 * tickets, each with its subject, its status, the time of its last message in the business's
 * zone and, where the platform has replied since this user last read it, a dot said in words.
 * The form: a subject and a message, each refused under itself. The thread: its messages oldest
 * first, each with its writer and its time, the text as written with its line breaks, then the
 * reply box; a closed ticket says a reply opens it again. A thread opens on its newest message,
 * the reply box in view under it, and comes back there each time a message is added. A view whose
 * read is under way says so and marks itself busy; one whose read did not come back says that and
 * offers to try again.
 */

type Props = { tickets: Tickets; words: HelpWords; clock: TenantClock };

const ROW = 'admin-help-row flex w-full flex-col gap-0.5 rounded-lg border px-3 py-2 text-left outline-none hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/50';

function submitted(send: () => void) {
    return (event: FormEvent) => {
        event.preventDefault();
        send();
    };
}

/** A line saying a read did not come back, with the button that asks again. */
function Failed({ text, words, onRetry }: { text: string; words: HelpWords; onRetry: () => void }) {
    return (
        <div className="admin-help-failed flex flex-wrap items-center gap-2 text-sm">
            <span>{text}</span>
            <Button onClick={onRetry}>{words.text('retry')}</Button>
        </div>
    );
}

function Unread({ words }: { words: HelpWords }) {
    return (
        <span className="admin-help-unread inline-flex items-center gap-1.5 text-xs font-medium text-primary">
            <span className="size-2 rounded-full bg-destructive" aria-hidden="true" />
            {words.text('list.unread')}
        </span>
    );
}

function Rows({ list, words, clock, onOpen }: { list: ListState; words: HelpWords; clock: TenantClock; onOpen: (uuid: string) => void }) {
    if (list.rows === null) {
        return list.loading ? <HelpLine role="status">{words.text('list.loading')}</HelpLine> : null;
    }

    if (list.rows.length === 0) {
        return <p className="admin-help-empty text-sm text-muted-foreground">{words.text('list.empty')}</p>;
    }

    return (
        <ul className="admin-help-rows flex flex-col gap-2">
            {list.rows.map((row) => (
                <li key={row.uuid}>
                    <button type="button" className={ROW} onClick={() => onOpen(row.uuid)}>
                        <span className="flex items-center justify-between gap-2">
                            <span className="min-w-0 truncate text-sm font-medium">{row.subject}</span>
                            {row.unread && <Unread words={words} />}
                        </span>
                        <span className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                            <span>{words.text(`statuses.${row.status}`)}</span>
                            <span>{words.text('list.last', { time: formatInstant(row.last_message_at, clock) })}</span>
                        </span>
                    </button>
                </li>
            ))}
        </ul>
    );
}

function List({ tickets, words, clock }: Props) {
    const list = tickets.list;

    return (
        <div className="admin-help-list flex flex-col gap-3" aria-busy={list.loading ? true : undefined}>
            <Button variant="primary" className="self-start" onClick={() => tickets.showForm('')} data-help-focus="">
                {words.text('open')}
            </Button>
            {list.failed && <Failed text={words.text('list.failed')} words={words} onRetry={tickets.retry} />}
            <Rows list={list} words={words} clock={clock} onOpen={tickets.showThread} />
        </div>
    );
}

function TicketForm({ tickets, words }: Omit<Props, 'clock'>) {
    const form = useFocusFirstFailing<HTMLFormElement>(tickets.refusal);
    const draft = tickets.form;

    return (
        <form ref={form} noValidate className="admin-help-form flex flex-col gap-3" onSubmit={submitted(tickets.sendTicket)}>
            <Button variant="link" className="self-start" onClick={tickets.showList}>
                {words.text('back')}
            </Button>
            <h3 className="text-base font-semibold">{words.text('form.title')}</h3>
            <Field id="admin-help-subject" label={words.text('form.subject')} error={draft.errors.subject}>
                {(control) => <TextInput {...control} value={draft.subject} maxLength={LONGEST_SUBJECT} onChange={(event) => tickets.typeSubject(event.target.value)} data-help-focus="" />}
            </Field>
            <Field id="admin-help-body" label={words.text('form.body')} error={draft.errors.body}>
                {(control) => <Textarea {...control} rows={6} value={draft.body} maxLength={LONGEST_MESSAGE} onChange={(event) => tickets.typeBody(event.target.value)} />}
            </Field>
            <Button type="submit" variant="primary" className="self-start" busy={tickets.writing === 'ticket'} busyLabel={words.text('form.sending')}>
                {words.text('form.send')}
            </Button>
        </form>
    );
}

function Message({ message, clock }: { message: HelpMessage; clock: TenantClock }) {
    return (
        <li className={cn('admin-help-message flex flex-col gap-1 rounded-lg p-3', message.side === 'platform' ? 'admin-help-message--platform bg-muted' : 'admin-help-message--business border')}>
            <p className="flex flex-wrap items-baseline justify-between gap-x-3 text-xs text-muted-foreground">
                <span className="font-medium text-foreground">{message.name}</span>
                <time dateTime={message.at}>{formatInstant(message.at, clock)}</time>
            </p>
            <p className="text-sm break-words whitespace-pre-line">{message.body}</p>
        </li>
    );
}

/**
 * The reply box, the last thing in a thread. It is brought into view, by the least scroll that
 * shows it whole, when the thread is first shown and each time its messages change in number: the
 * newest message stands just over it.
 */
function Reply({ tickets, words, count }: Omit<Props, 'clock'> & { count: number }) {
    const form = useFocusFirstFailing<HTMLFormElement>(tickets.refusal);

    useEffect(() => {
        form.current?.scrollIntoView({ block: 'nearest' });
    }, [form, count]);

    return (
        <form ref={form} noValidate className="admin-help-reply flex scroll-mb-4 flex-col gap-2" onSubmit={submitted(tickets.sendReply)}>
            <Field id="admin-help-reply" label={words.text('thread.reply')} error={tickets.replyError}>
                {(control) => <Textarea {...control} rows={4} value={tickets.reply} maxLength={LONGEST_MESSAGE} onChange={(event) => tickets.typeReply(event.target.value)} />}
            </Field>
            <Button type="submit" variant="primary" className="self-start" busy={tickets.writing === 'reply'} busyLabel={words.text('thread.sending')}>
                {words.text('thread.send')}
            </Button>
        </form>
    );
}

function Conversation({ thread, tickets, words, clock }: Props & { thread: HelpThread }) {
    return (
        <>
            <div className="flex flex-col gap-1">
                <h3 className="text-base font-semibold break-words">{thread.subject}</h3>
                <span className="text-xs text-muted-foreground">{words.text(`statuses.${thread.status}`)}</span>
            </div>
            {thread.status === 'closed' && <HelpLine className="admin-help-closed">{words.text('thread.closed')}</HelpLine>}
            <ol className="admin-help-messages flex flex-col gap-2">
                {thread.messages.map((message) => (
                    <Message key={message.id} message={message} clock={clock} />
                ))}
            </ol>
            <Reply tickets={tickets} words={words} count={thread.messages.length} />
        </>
    );
}

/**
 * What a thread view shows while its thread is not there: the read under way, or, once no read
 * is, the line that asks again.
 */
function Pending({ loading, tickets, words }: { loading: boolean; tickets: Tickets; words: HelpWords }) {
    if (loading) {
        return <HelpLine role="status">{words.text('thread.loading')}</HelpLine>;
    }

    return <Failed text={words.text('thread.failed')} words={words} onRetry={tickets.retry} />;
}

function Thread({ tickets, words, clock, uuid }: Props & { uuid: string }) {
    const state = tickets.thread;
    const mine = state.uuid === uuid;
    const thread = mine ? state.value : null;
    const loading = mine && state.loading;

    return (
        <div className="admin-help-thread flex flex-col gap-3" aria-busy={loading ? true : undefined}>
            <Button variant="link" className="self-start" onClick={tickets.showList} data-help-focus="">
                {words.text('back')}
            </Button>
            {thread !== null && state.failed && <Failed text={words.text('thread.failed')} words={words} onRetry={tickets.retry} />}
            {thread === null ? <Pending loading={loading} tickets={tickets} words={words} /> : <Conversation thread={thread} tickets={tickets} words={words} clock={clock} />}
        </div>
    );
}

export function TicketsTab({ tickets, words, clock }: Props) {
    const view = tickets.view;

    if (view.kind === 'form') {
        return <TicketForm tickets={tickets} words={words} />;
    }

    if (view.kind === 'thread') {
        return <Thread key={view.uuid} tickets={tickets} words={words} clock={clock} uuid={view.uuid} />;
    }

    return <List tickets={tickets} words={words} clock={clock} />;
}
