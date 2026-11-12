import { QueryClientProvider } from '@tanstack/react-query';
import { Component, useEffect, useMemo, useState, type ReactNode } from 'react';
import { createQueryClient, type Api, type Notice } from './api';
import type { MountOptions } from './attributes';
import type { BootConfig } from './config';
import { Flow } from './Flow';
import { Frame } from './Frame';
import { prefixOf, type Language } from './language';
import { LanguageSwitch, type PageAddress } from './LanguageSwitch';
import { ManageView } from './ManageView';
import type { MyBookingsPage } from './myBookings';
import { MyBookingsView } from './MyBookingsView';
import { NoticeRegion } from './NoticeRegion';
import { BUNDLED_LINES, wordsFor, WordsContext, type LineKey, type Lines } from './words';

/*
 * The shell: the loading and unavailable states while the config is on the wire, and the flow
 * once it is in hand. One error boundary wraps the whole app: by the time a render crashes the
 * noscript sentence is long gone, and without the boundary the customer would get a silently
 * empty box. One query client per mount, made here and living as long as the app does.
 *
 * The app's language: the three sentences rendered before or without the config ride in both
 * languages and are read in the start language, the host page's lang where it names English or
 * Greek, else English; once the config is in hand the app holds its language, the server's own
 * reading of the request's, and hands its words down to every view. The switch renders beside
 * the view, a link to the same page under the other language's prefix on the hosted page and, in
 * the embed, an in-app control that changes the language the app holds with no request, every
 * later call carrying the new one.
 */

export const CONSOLE_PREFIX = '[kr]';

export const LOADING_SENTENCE: LineKey = 'shell.loading';

export const UNAVAILABLE_SENTENCE: LineKey = 'shell.unavailable';

export const CRASHED_SENTENCE: LineKey = 'shell.crashed';

// The shell's three notices go through the one region like every other (7.3): the loading
// line is polite, so its region mounts empty and takes the sentence one effect later; the
// unavailable and crashed sentences are alerts, which announce on appearance.
const LOADING_NOTICE: Notice = { key: LOADING_SENTENCE, tone: 'polite' };

const UNAVAILABLE_NOTICE: Notice = { key: UNAVAILABLE_SENTENCE, tone: 'alert' };

const CRASHED_NOTICE: Notice = { key: CRASHED_SENTENCE, tone: 'alert' };

/**
 * Where the config comes from: already in hand on the hosted page, or still on the wire for
 * the embed, which shows its loading state until that lands.
 */
export type ConfigSource =
    | { readonly kind: 'ready'; readonly config: BootConfig }
    | { readonly kind: 'pending'; readonly promise: Promise<BootConfig> };

type Phase =
    | { readonly status: 'loading' }
    | { readonly status: 'ready'; readonly config: BootConfig }
    | { readonly status: 'unavailable' };

interface AppProps {
    readonly source: ConfigSource;
    readonly options: MountOptions;
    readonly api: Api;
    /** My bookings' list, inlined by the hosted page beside the config; null everywhere else. */
    readonly myBookings?: MyBookingsPage | null;
    /** The manage page's flashed sentence, inlined by the hosted page beside the config; null everywhere else. */
    readonly notice?: string | null;
    /** The host page's language, the embed's start, or null where its lang names neither language. */
    readonly start?: Language | null;
    /** The hosted page's address, which the switch's link is computed from; null in the embed. */
    readonly page?: PageAddress | null;
    /** Told the language the switch changed to, so the mount's lang follows it. */
    readonly onLanguage?: (language: Language) => void;
    /** Both languages' lines; the bundle's own unless a test hands others in. */
    readonly lines?: Lines;
}

export function App(props: AppProps): ReactNode {
    const [client] = useState(createQueryClient);
    const { lines = BUNDLED_LINES, start = null } = props;
    const before = useMemo(() => wordsFor(lines, start ?? 'en'), [lines, start]);

    return (
        <WordsContext.Provider value={before}>
            <ErrorBoundary>
                <QueryClientProvider client={client}>
                    <Shell {...props} lines={lines} />
                </QueryClientProvider>
            </ErrorBoundary>
        </WordsContext.Provider>
    );
}

type ShellProps = AppProps & { readonly lines: Lines };

function Shell({ source, options, api, myBookings = null, notice = null, page = null, onLanguage, lines }: ShellProps): ReactNode {
    const [phase, setPhase] = useState<Phase>(() =>
        source.kind === 'ready' ? { status: 'ready', config: source.config } : { status: 'loading' },
    );
    // The language the switch chose, which the config's gives way to once it is pressed.
    const [chosen, setChosen] = useState<Language | null>(null);

    useEffect(() => {
        if (source.kind !== 'pending') {
            return undefined;
        }

        let live = true;

        source.promise.then(
            (config) => {
                if (live) {
                    setPhase({ status: 'ready', config });
                }
            },
            () => {
                if (live) {
                    setPhase({ status: 'unavailable' });
                }
            },
        );

        return () => {
            live = false;
        };
    }, [source]);

    // Every request of the app carries its language once the config has named it: the config's, the
    // server's own reading of the request's language, until the switch chooses the other.
    const language = phase.status === 'ready' ? (chosen ?? phase.config.language) : undefined;
    const spoken = useMemo<Api>(() => (language === undefined ? api : { ...api, language }), [api, language]);
    const words = useMemo(() => wordsFor(lines, language ?? 'en'), [lines, language]);

    function switchTo(next: Language): void {
        setChosen(next);
        onLanguage?.(next);
    }

    // The prefix the hosted page was opened under, which My bookings' forms and every Book again
    // keep; none in the embed, whose page is not ours.
    const prefix = page === null ? null : prefixOf(page.pathname);

    /**
     * The mode the mount named: the booking flow, or the view a magic link lands on. One bundle,
     * one config and one shell behind both, so the embed on a tenant's own page shows either
     * exactly as the hosted page does. The My bookings list is the hosted page's alone: the boot
     * reads it as list only where the page inlined both the config and the list, and the list is
     * what it renders.
     */
    function view(config: BootConfig): ReactNode {
        if (options.mode === 'list' && myBookings !== null) {
            return <MyBookingsView config={config} options={options} page={myBookings} api={spoken} prefix={prefix} />;
        }

        return options.mode === 'manage' ? <ManageView config={config} options={options} api={spoken} notice={notice} prefix={prefix} /> : <Flow config={config} options={options} api={spoken} />;
    }

    switch (phase.status) {
        case 'ready':
            return (
                <WordsContext.Provider value={words}>
                    {view(phase.config)}
                    <LanguageSwitch lines={lines} language={words.language} page={page} onSwitch={switchTo} />
                </WordsContext.Provider>
            );
        case 'loading':
            return (
                <Frame mode={options.mode} state="loading">
                    <NoticeRegion notice={LOADING_NOTICE} />
                </Frame>
            );
        case 'unavailable':
            return (
                <Frame mode={options.mode} state="unavailable">
                    <NoticeRegion notice={UNAVAILABLE_NOTICE} />
                </Frame>
            );
    }
}

interface BoundaryState {
    readonly crashed: boolean;
}

/**
 * Catches render-phase throws only. The original error is re-reported to the console with
 * the recognisable prefix, so browser tooling still sees it; the customer sees one sentence
 * they can act on and no detail they cannot.
 */
export class ErrorBoundary extends Component<{ readonly children: ReactNode }, BoundaryState> {
    override state: BoundaryState = { crashed: false };

    static getDerivedStateFromError(): BoundaryState {
        return { crashed: true };
    }

    override componentDidCatch(error: Error): void {
        console.error(CONSOLE_PREFIX, 'The booking app crashed while rendering.', error);
    }

    override render(): ReactNode {
        if (this.state.crashed) {
            return <NoticeRegion notice={CRASHED_NOTICE} />;
        }

        return this.props.children;
    }
}
