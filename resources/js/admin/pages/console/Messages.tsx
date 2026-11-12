import { Head, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { Pagination } from '@/components/pagination';
import { SettledList } from '@/components/settled-list';
import { Button } from '@/components/ui/button';
import { Segmented } from '@/components/ui/segmented';
import { usePartialRead } from '@/lib/partial-read';
import { useShell } from '@/lib/strings';
import { NOT_DONE, owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import type { ConsoleProps } from '@/types';

/*
 * The console's contact messages: the messages the marketing site's contact form stored, newest
 * first, twenty-five a page by the admin's pagination, inside the console's shell, with one flag
 * and no reply. Each message is the instant it arrived as the server spelled it on the console's
 * clock, which the top bar names once for the screen, the sender's name, the address as a mailto:
 * link so the reply is written in the operator's own mail, the language it was sent in, in words,
 * and the message whole, each of its line breaks a break.
 *
 * The view, open or archived, and the page live in the query string, which the server reads, and
 * nowhere else: every change is a partial reload of the list, and nothing is optimistic, the
 * messages on screen being the server's last answer, a read that fails saying so above them.
 *
 * Archive, on each open message, posts no body to that message's archive and shows its busy state
 * on that message until the server's answer lands, one archive at a time. What was archived
 * arrives with the redirect back to this screen as it was, the list read again and the flash, a
 * message already archived among them as an error toast that stays; a failure the server could not
 * word is one too. There is no unarchive.
 */

/** The languages of App\Site\SiteLanguage, as the server sends each message's. */
type MessageLanguage = 'en' | 'el';

/** The views of App\Platform\InboxView. */
type MessageView = 'open' | 'archived';

export type MessageRow = {
    id: number;
    /** The instant it arrived, spelled by the server on the console's clock, which the top bar names. */
    received: string;
    name: string;
    email: string;
    language: MessageLanguage;
    /** What the sender wrote, as it was stored, its line breaks whatever their machine wrote. */
    message: string;
};

type MessagesPage = { rows: MessageRow[]; total: number; page: number; page_size: number };

type Props = ConsoleProps & {
    filters: { view: MessageView };
    messages: MessagesPage;
};

const MESSAGES = '/messages';

/** What a change of view or a page reads again: the messages and the view they answer. */
const LIST_PROPS = ['messages', 'filters'];

const MESSAGES_TITLE = 'Messages';

/** Each language in words, one per case. */
export const LANGUAGE_WORDS: Record<MessageLanguage, string> = {
    en: 'English',
    el: 'Greek',
};

const VIEWS: readonly { value: MessageView; label: string }[] = [
    { value: 'open', label: 'Open' },
    { value: 'archived', label: 'Archived' },
];

/** Each view's list in words, as its card is headed. */
const VIEW_HEADINGS: Record<MessageView, string> = {
    open: 'Open messages',
    archived: 'Archived messages',
};

const VIEW_LABEL = 'Which messages to show';

export const NO_OPEN = 'No message is waiting.';

export const NO_ARCHIVED = 'No message is archived yet.';

export const MESSAGES_LIST_ERROR = 'The messages could not be loaded. Check your connection and try again.';

/**
 * The mailto: link of an address: every character a URI reserves written as its escape, so an
 * address the form let through cannot add a header to the reply, and the at sign and a plus kept
 * as written.
 */
export function mailtoHref(email: string): string {
    return `mailto:${encodeURIComponent(email).replace(/%40/g, '@').replace(/%2B/g, '+')}`;
}

/** The message as lines, a break between each, whatever ends a line on the sender's machine. */
function MessageBody({ message }: { message: string }) {
    const lines = message.split(/\r\n|\r|\n/);

    return (
        <p className="admin-message__body mt-2 break-words whitespace-pre-wrap">
            {lines.map((line, index) => (
                <Fragment key={index}>
                    {index > 0 && <br />}
                    {line}
                </Fragment>
            ))}
        </p>
    );
}

/** The query string of a view and a page, the defaults left out, so the first page of the open messages is the bare screen. */
function messagesQuery(view: MessageView, page: number): Record<string, string> {
    const query: Record<string, string> = {};

    if (view !== 'open') {
        query['view'] = view;
    }

    if (page > 1) {
        query['page'] = String(page);
    }

    return query;
}

/**
 * Archive on the open messages: one at a time, busy on its own message until the server's answer
 * lands, which is this screen again with the list read afresh. The press carries the screen's
 * view and page in the archive's own query, which the server builds its answer's address from.
 */
function useArchive(): { archiving: number | null; archive: (id: number, query: Record<string, string>) => void } {
    const toasts = useToasts();
    const shell = useShell();
    const [archiving, setArchiving] = useState<number | null>(null);

    const archive = (id: number, query: Record<string, string>) => {
        if (archiving !== null) {
            return;
        }

        const search = new URLSearchParams(query).toString();

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(
            `${MESSAGES}/${id}/archive${search === '' ? '' : `?${search}`}`,
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setArchiving(id);
                    owned.onStart();
                },
                onFinish: () => {
                    setArchiving(null);
                    owned.onFinish();
                },
            },
        );
    };

    return { archiving, archive };
}

function Message({ message, archivable, busy, onArchive }: { message: MessageRow; archivable: boolean; busy: boolean; onArchive: () => void }) {
    const from = `message-${message.id}-from`;

    return (
        <li className="admin-message border-t px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p id={from} className="flex flex-wrap items-baseline gap-x-2">
                    <span className="admin-message__name font-medium">{message.name}</span>
                    <a className="admin-message__email text-sm underline" href={mailtoHref(message.email)}>
                        {message.email}
                    </a>
                </p>
                <p className="flex flex-wrap gap-x-3 text-sm text-muted-foreground">
                    <span className="admin-message__received">Received {message.received}</span>
                    <span className="admin-message__language">Sent in {LANGUAGE_WORDS[message.language]}</span>
                </p>
            </div>
            <MessageBody message={message.message} />
            {archivable && (
                <Button className="admin-message__archive mt-3" aria-describedby={from} busy={busy} busyLabel="Archiving" onClick={onArchive}>
                    Archive
                </Button>
            )}
        </li>
    );
}

export default function Messages() {
    const { props } = usePage<Props>();
    const list = usePartialRead(LIST_PROPS);
    const { archiving, archive } = useArchive();
    const current = props.filters.view;
    const rows = props.messages.rows;
    const load = (view: MessageView, page: number) => list.read(MESSAGES, messagesQuery(view, page));

    return (
        <>
            <Head title={MESSAGES_TITLE} />
            <div className="admin-messages-screen flex flex-col gap-4">
                <h1 className="text-2xl font-semibold">{MESSAGES_TITLE}</h1>
                <Segmented className="admin-messages-view self-start" label={VIEW_LABEL} options={VIEWS} value={current} onChange={(view) => load(view, 1)} />
                <SettledList
                    count={rows.length}
                    state={{ loading: list.loading, error: list.error }}
                    errorSentence={MESSAGES_LIST_ERROR}
                    empty={
                        <div className="admin-messages-empty rounded-xl border p-6 text-center">
                            <p className="text-muted-foreground">{current === 'open' ? NO_OPEN : NO_ARCHIVED}</p>
                        </div>
                    }
                >
                    <section className="admin-card admin-messages rounded-xl border" aria-labelledby="messages-head">
                        <header className="flex items-center justify-between px-4 py-3">
                            <h2 id="messages-head" className="font-semibold">
                                {VIEW_HEADINGS[current]}
                            </h2>
                            <p className="admin-messages__count text-sm text-muted-foreground">
                                {rows.length} of {props.messages.total}
                            </p>
                        </header>
                        <ul>
                            {rows.map((message) => (
                                <Message key={message.id} message={message} archivable={current === 'open'} busy={archiving === message.id} onArchive={() => archive(message.id, messagesQuery(current, props.messages.page))} />
                            ))}
                        </ul>
                        <Pagination page={props.messages.page} total={props.messages.total} pageSize={props.messages.page_size} onPage={(page) => load(current, page)} />
                    </section>
                </SettledList>
            </div>
        </>
    );
}
