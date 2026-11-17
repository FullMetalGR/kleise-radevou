import type { HelpStrings } from './words';

/*
 * The help bubble's and its panel's props and the bodies and refusals of their four routes, as
 * App\Http\HelpProps and App\Http\Controllers\Admin\HelpController send and read them.
 *
 * Every page a signed-in user of the business sees carries help, the bubble's: whether the viewer
 * has tickets at all (false on the demo account, which gets the answers alone), the dot, and the
 * ticket a link from a mail opened the admin on. A support session gets no help prop at all, and
 * neither does the sign-in page. The panel's four other props are sent only when a partial reload
 * names them: help_panel (its words and the answers), help_tickets (the list) and help_thread (the
 * thread the last write named). Instants are RFC 3339 in UTC; the page shows them in the
 * business's zone and clock as every other screen does.
 */

/** A ticket's status: only the console closes one, and a business message reopens it. */
export type HelpStatus = 'open' | 'closed';

/** The bubble's prop, on every page of a signed-in user of the business. */
export type HelpBubble = {
    /** Whether this viewer has the Tickets tab: false on the demo account. */
    tickets: boolean;
    /** Whether any of the business's fifty listed tickets has a platform message this user has not opened. */
    unread: boolean;
    /** The uuid of the ticket a link from a mail opened the admin on, for this one page load; null otherwise. */
    open: string | null;
};

/** One answer under a topic: its question and one to three plain-text paragraphs. */
export type HelpAnswer = {
    key: string;
    question: string;
    answer: string[];
};

/** One topic of the answers, in the order the panel shows them. */
export type HelpTopic = {
    key: string;
    title: string;
    answers: HelpAnswer[];
};

/** help_panel: the panel's words, the whole of lang/{en,el}/admin/help.php, and the answers, in the request's language. */
export type HelpPanel = {
    words: HelpStrings;
    answers: HelpTopic[];
};

/** One row of help_tickets: the business's fifty tickets with the newest activity, newest first. */
export type HelpTicketRow = {
    uuid: string;
    subject: string;
    status: HelpStatus;
    last_message_at: string;
    unread: boolean;
};

/**
 * One message of a thread: name is the writer's name as written for the business's users, and the
 * platform's support name for the platform's; mine marks this user's own.
 */
export type HelpMessage = {
    /** The message's place in this thread, from one, oldest first: a key among this thread's messages alone, never a stored message's identity. */
    id: number;
    side: 'business' | 'platform';
    name: string;
    mine: boolean;
    body: string;
    at: string;
};

/** help_thread: the thread the last write named, its messages oldest first. */
export type HelpThread = {
    uuid: string;
    subject: string;
    status: HelpStatus;
    messages: HelpMessage[];
};

/** The help props as a page receives them; every one is absent where it is not sent. */
export type HelpProps = {
    help?: HelpBubble;
    help_panel?: HelpPanel;
    help_tickets?: HelpTicketRow[];
    help_thread?: HelpThread | null;
};

/*
 * The four routes, each a plain Inertia visit; a write answers by going back to the page it came
 * from with its thread flashed, so the write sent with only ['help_thread'] (and help_tickets or
 * help where the panel wants them fresh) reads that thread back:
 *
 *   POST /admin/help, { subject, body }: a new ticket, read by its writer.
 *   POST /admin/help/{uuid}/messages, { body }: a reply, read by its writer.
 *   POST /admin/help/{uuid}/read: a thread opened, marked read for this user.
 *   GET /admin/help/{uuid}: the link a mail carries, landing on the user's first screen with
 *   help.open set to the ticket.
 *
 * A refusal is the house envelope under code, its data.detail the words to show and data.field,
 * where it has one, the HelpField it stands beside: invalid_request (400) for a subject or a
 * message too long or empty, help_limited (429) at an hourly limit, help_demo (403) on the demo
 * account. A ticket the business does not hold answers 404.
 */

/** The field a refusal names, beside which the panel shows it. */
export type HelpField = 'subject' | 'body';
