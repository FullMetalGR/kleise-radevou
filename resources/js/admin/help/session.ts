import { useCallback, useRef, useState } from 'react';
import { type ShellWords, useShell } from '@/lib/strings';
import { NOT_DONE } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import type { Language } from '../../booking/language';
import { ask } from './requests';
import { type Tickets, useTickets } from './tickets';
import type { HelpPanel, HelpTopic } from './types';
import { type HelpWords, helpWordsOf } from './words';

/*
 * The help panel's state, held by the bubble for as long as the admin's frame stands, so what was
 * typed outlives the panel's closing: the search, the answer open under it, the drafts of a new
 * ticket and of every reply. The panel's words and answers are asked for the first time it opens
 * and kept, in the language they came in; a change of language asks for them again on the next
 * opening. The panel opens only once they are there: until then the bubble says it is busy.
 */

export type Tab = 'answers' | 'tickets';

type Loaded = { panel: HelpPanel; language: Language };

export type HelpSession = {
    /** The panel's words, null until they have come in the language shown. */
    words: HelpWords | null;
    topics: HelpTopic[];
    open: boolean;
    /** Whether the panel's words and answers are being asked for. */
    opening: boolean;
    tab: Tab;
    query: string;
    /** The one answer shown open, as its topic's key and its own; null where none is. */
    answer: string | null;
    tickets: Tickets;
    openChange: (open: boolean) => void;
    close: () => void;
    /** Opens the panel on a ticket's thread, as a link from a mail asks. */
    openOn: (uuid: string) => void;
    chooseTab: (tab: Tab) => void;
    search: (query: string) => void;
    toggleAnswer: (answer: string) => void;
    /** Shows the new ticket's form, from either tab. */
    newTicket: () => void;
};

/** The panel's words and answers where they came in the language shown; null where they must be asked for. */
function panelIn(loaded: Loaded | null, language: Language): HelpPanel | null {
    return loaded !== null && loaded.language === language ? loaded.panel : null;
}

/** Asks for the panel's words and answers; a refusal is handed on as the sentence to show. */
function askPanel(shell: ShellWords, got: (panel: HelpPanel, after: (next: () => void) => void) => void, refused: (sentence: string) => void, ended: () => void): void {
    ask(
        {
            to: null,
            only: ['help_panel', 'help'],
            landed: (props, after) => {
                if (props.help_panel !== undefined) {
                    got(props.help_panel, after);
                }
            },
            refused: (failure) => refused(failure.sentence ?? shell.text(NOT_DONE)),
            ended,
        },
        shell,
    );
}

export function useHelpSession(): HelpSession {
    const shell = useShell();
    const toasts = useToasts();
    const [loaded, setLoaded] = useState<Loaded | null>(null);
    const [open, setOpen] = useState(false);
    const [opening, setOpening] = useState(false);
    const [tab, setTab] = useState<Tab>('answers');
    const [query, setQuery] = useState('');
    const [answer, setAnswer] = useState<string | null>(null);
    const asking = useRef(false);
    const current = panelIn(loaded, shell.language);
    const words = current && helpWordsOf(current.words, shell.language);
    const tickets = useTickets(words, shell, (message) => toasts.push('error', message), open && current !== null && tab === 'tickets');
    const close = useCallback(() => setOpen(false), []);

    /** Runs once the panel's words and answers are there, asking for them first if they are not. */
    const withPanel = (then: () => void) => {
        if (current !== null) {
            then();

            return;
        }

        if (asking.current) {
            return;
        }

        const language = shell.language;
        asking.current = true;
        setOpening(true);
        askPanel(
            shell,
            (panel, after) => {
                setLoaded({ panel, language });
                after(then);
            },
            (sentence) => toasts.push('error', sentence),
            () => {
                asking.current = false;
                setOpening(false);
            },
        );
    };

    const openChange = (next: boolean) => {
        if (!next) {
            setOpen(false);

            return;
        }

        withPanel(() => {
            setOpen(true);

            if (tab === 'tickets') {
                tickets.enter(tickets.view, false);
            }
        });
    };

    const chooseTab = (next: Tab) => {
        setTab(next);

        if (next === 'tickets') {
            tickets.enter(tickets.view, false);
        }
    };

    return {
        words,
        topics: current?.answers ?? [],
        open: open && current !== null,
        opening,
        tab,
        query,
        answer,
        tickets,
        openChange,
        close,
        openOn: (uuid) =>
            withPanel(() => {
                setTab('tickets');
                setOpen(true);
                tickets.showThread(uuid);
            }),
        chooseTab,
        search: setQuery,
        toggleAnswer: (key) => setAnswer((now) => (now === key ? null : key)),
        newTicket: () => {
            setTab('tickets');
            tickets.showForm(query);
        },
    };
}
