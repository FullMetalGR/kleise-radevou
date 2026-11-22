import { Head, Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, Fragment, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, useFocusFirstFailing } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/input';
import { useShell } from '@/lib/strings';
import { NOT_DONE, owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import type { ConsoleProps } from '@/types';
import { threadPath, type TicketSummary, TicketStatusWords } from './Tickets';

/*
 * One help ticket on the console: its subject, its business by name with a link to the business's
 * page on this console, its status and its newest message's instant, then its messages oldest
 * first, each under its writer's name as written, the side it came from and its instant as the
 * server spelled it on the console's clock. A message is plain text: its line breaks are breaks,
 * nothing in it is read as markup and nothing is turned into a link. The platform's messages carry
 * the operator's own name here, for the platform's records.
 *
 * The reply box posts the reply as this operator and leaves the status as it is; a reply the server
 * refuses comes back with its sentence under the box, what was written kept and focus moved to the
 * box, and one it takes empties the box. Close sits on an open ticket and Reopen on a closed one.
 * Each press shows its busy state until the server's answer lands, one at a time: the thread again,
 * read afresh, with the flash as a toast, a ticket another operator already moved among them as an
 * error toast that stays. A failure the server could not word is one too.
 */

/** The sides of App\Help\HelpSide. */
type Side = 'business' | 'platform';

export type ThreadMessage = {
    id: number;
    side: Side;
    /** The writer's name as written: a business user's, or the operator's. */
    name: string;
    /** The instant, spelled by the server on the console's clock, which the shell names. */
    at: string;
    /** What the writer wrote, as it was stored. */
    body: string;
};

type Props = ConsoleProps & {
    ticket: TicketSummary;
    messages: ThreadMessage[];
};

type Act = 'messages' | 'close' | 'reopen';

/** Each side in words, as a message names where it came from. */
const SIDE_WORDS: Record<Side, string> = {
    business: 'Business',
    platform: 'Platform',
};

const ALL_TICKETS = 'All tickets';

const REPLY_FIELD = 'ticket-reply';

const REPLY_LABEL = 'Reply';

export const REPLY_HELP = 'Up to 5,000 characters. The business reads it under the platform\'s support name.';

export const SEND = 'Send reply';

const SENDING = 'Sending';

export const CLOSE = 'Close';

const CLOSING = 'Closing';

export const REOPEN = 'Reopen';

const REOPENING = 'Reopening';

/** The message as lines, a break between each, whatever ends a line on the writer's machine. */
function MessageBody({ body }: { body: string }) {
    const lines = body.split(/\r\n|\r|\n/);

    return (
        <p className="admin-ticket-message__body mt-2 break-words whitespace-pre-wrap">
            {lines.map((line, index) => (
                <Fragment key={index}>
                    {index > 0 && <br />}
                    {line}
                </Fragment>
            ))}
        </p>
    );
}

function Message({ message }: { message: ThreadMessage }) {
    return (
        <li className="admin-ticket-message border-t px-4 py-3" data-side={message.side}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="flex flex-wrap items-baseline gap-x-2">
                    <span className="admin-ticket-message__name font-medium">{message.name}</span>
                    <span className="admin-ticket-message__side text-sm text-muted-foreground">{SIDE_WORDS[message.side]}</span>
                </p>
                <p className="admin-ticket-message__at text-sm text-muted-foreground">{message.at}</p>
            </div>
            <MessageBody body={message.body} />
        </li>
    );
}

/**
 * The thread's three writes: one at a time, busy on its own control until the server's answer
 * lands, which is this thread again, read afresh.
 */
function useTicketPost(id: number): { busy: Act | null; post: (act: Act, body: Record<string, string>, onSuccess: () => void, onError: (errors: Record<string, string>) => void) => void } {
    const toasts = useToasts();
    const shell = useShell();
    const [busy, setBusy] = useState<Act | null>(null);

    const post = (act: Act, body: Record<string, string>, onSuccess: () => void, onError: (errors: Record<string, string>) => void) => {
        if (busy !== null) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(`${threadPath(id)}/${act}`, body, {
            preserveScroll: true,
            onStart: () => {
                setBusy(act);
                owned.onStart();
            },
            onFinish: () => {
                setBusy(null);
                owned.onFinish();
            },
            onSuccess,
            onError,
        });
    };

    return { busy, post };
}

/**
 * The reply box. A refusal is kept as a new object each time the server refuses, so the same
 * sentence twice running is still two refusals, and each one moves focus to the box, where its
 * sentence is read out as the box's description.
 */
function ReplyBox({ busy, onSend }: { busy: boolean; onSend: (reply: string, sent: () => void, refused: (sentence: string) => void) => void }) {
    const shell = useShell();
    const [reply, setReply] = useState('');
    const [refusal, setRefusal] = useState<{ sentence: string } | null>(null);
    const form = useFocusFirstFailing(refusal);

    const send = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setRefusal(null);
        onSend(
            reply,
            () => setReply(''),
            (sentence) => setRefusal({ sentence: sentence === '' ? shell.text(NOT_DONE) : sentence }),
        );
    };

    return (
        <form ref={form} className="admin-ticket-reply flex flex-col gap-3 border-t px-4 py-3" onSubmit={send}>
            <Field id={REPLY_FIELD} label={REPLY_LABEL} help={REPLY_HELP} error={refusal?.sentence}>
                {(control) => <Textarea {...control} className="admin-ticket-reply__body" name="body" rows={6} value={reply} onChange={(event) => setReply(event.target.value)} />}
            </Field>
            <Button type="submit" variant="primary" className="admin-ticket-reply__send self-start" busy={busy} busyLabel={SENDING}>
                {SEND}
            </Button>
        </form>
    );
}

export default function Ticket() {
    const { props } = usePage<Props>();
    const ticket = props.ticket;
    const { busy, post } = useTicketPost(ticket.id);
    const open = ticket.status === 'open';
    const move = () => post(open ? 'close' : 'reopen', {}, () => undefined, () => undefined);

    return (
        <>
            <Head title={ticket.subject} />
            <div className="admin-ticket-screen flex flex-col gap-4">
                <PageHeader
                    breadcrumb={
                        <Link href="/tickets" className="admin-ticket-back self-start text-sm underline-offset-4 hover:underline">
                            {ALL_TICKETS}
                        </Link>
                    }
                    title={<span className="admin-ticket__subject">{ticket.subject}</span>}
                    lead={
                        <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-foreground">
                            <Link href={ticket.tenant.url} className="admin-ticket__tenant font-medium underline-offset-4 hover:underline">
                                {ticket.tenant.name}
                            </Link>
                            <TicketStatusWords ticket={ticket} name="admin-ticket" />
                            <span className="admin-ticket__last text-muted-foreground">Last message {ticket.last_message}</span>
                        </span>
                    }
                    actions={
                        <Button className={open ? 'admin-ticket-close' : 'admin-ticket-reopen'} busy={busy === 'close' || busy === 'reopen'} busyLabel={open ? CLOSING : REOPENING} onClick={move}>
                            {open ? CLOSE : REOPEN}
                        </Button>
                    }
                />
                <Card flush className="admin-card admin-ticket" aria-labelledby="ticket-messages-head">
                    <header className="px-4 py-3">
                        <h2 id="ticket-messages-head" className="font-semibold">
                            Messages
                        </h2>
                    </header>
                    <ol className="admin-ticket-messages">
                        {props.messages.map((message) => (
                            <Message key={message.id} message={message} />
                        ))}
                    </ol>
                    <ReplyBox busy={busy === 'messages'} onSend={(reply, sent, refused) => post('messages', { body: reply }, sent, (errors) => refused(errors.body ?? ''))} />
                </Card>
            </div>
        </>
    );
}
