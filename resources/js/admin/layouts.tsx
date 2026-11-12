import { router, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useState } from 'react';
import { LanguageControl } from './shell/LanguageControl';
import { NarrowedBanner } from './shell/NarrowedBanner';
import { Shell } from './shell/Shell';
import { SupportBanner } from './shell/SupportBanner';
import { ConsoleWordmark, Wordmark } from './shell/Wordmark';
import { applyTheme, readTheme, type Theme, writeTheme } from './theme';
import { FlashToast, useToasts } from './toast/toasts';
import type { ConsoleProps, GuestProps, SharedProps } from './types';
import { Button } from '@/components/ui/button';
import { clockOf } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { NOT_DONE, owningFailure } from '@/lib/visits';
import { NewBookingProvider, useNewBooking } from './wizard/NewBooking';

/*
 * The frames a page can have. Every admin screen but the door renders inside the admin's shell;
 * the door and the signup's pages render alone. A console page renders inside the console's shell,
 * built from the same parts, and the console's door renders alone as the admin's does. Each
 * consumes the flash prop into a toast. They are Inertia persistent layouts, so a shell's own
 * state, the theme and a sign-out in flight, lives across visits.
 *
 * The brand: the admin's top bar carries a small wordmark beside the business's name and nothing
 * else of the brand, so the admin's theme applies to all of it; the console's shell carries the
 * wordmark in place of the console's name and wears the brand's scope, and so do the signup's
 * pages, inside the central host's frame, and the console's door, each keeping the admin's
 * primitives. Every wordmark names the brand prop's public name.
 *
 * The admin's banner slot holds two notices, the support session's first and the narrowing
 * record's under it; each renders nothing while it has nothing to say. The support banner's End
 * session posts the admin's sign-out as the top bar's Sign out does, each control with a busy
 * state of its own.
 *
 * The language: the root view writes the page's language as the document's lang on a full load,
 * and the admin's frames keep it in step on every visit after, so a sign-in that lands a user in
 * their own language, a sign-out back to the door in the tenant's, and a change of the user's own
 * language each leave the document in the page's. The top bar's language control renders where the
 * server shares its choices, to a signed-in user outside a support session; its press writes the
 * user's own language, busy until the next page arrives in it, a refusal an error toast that
 * stays.
 */

const LOGOUT_URL = '/admin/logout';

/** The user's own language, PUT /admin/language/{language}. */
const LANGUAGE_URL = '/admin/language';

/** The console's sign-out, on the console's own host. */
const CONSOLE_LOGOUT_URL = '/logout';

/** The class that takes the brand's primary into the admin's tokens inside it, app.css. */
const BRAND_SCOPE = 'brand-scope';

/** The console's door, which renders outside the console's shell. */
const CONSOLE_DOOR = 'console/Login';

/** The console's top bar names its clock once for every instant on the screen. */
export function consoleZoneLine(zone: string): string {
    return `Times are in ${zone}`;
}

function localStorageOf(): Storage {
    return window.localStorage;
}

/** Keeps the document's lang the page's language, where the page names one. */
function useDocumentLanguage(language: string | undefined): void {
    useEffect(() => {
        if (language !== undefined) {
            document.documentElement.lang = language;
        }
    }, [language]);
}

/**
 * Keeps the body's data-theme the tenant's colour theme the shared props carry, so a Settings
 * save that changes it repaints the admin with no reload; neutral where none is shared. The
 * light or dark mode is another thing, useTheme's.
 */
function useColourTheme(colourTheme: string | undefined): void {
    useEffect(() => {
        document.body.dataset.theme = colourTheme ?? 'neutral';
    }, [colourTheme]);
}

/** The language being written while its write is in flight, and the press that writes one. */
function useLanguageChoice(): [string | null, (language: string) => void] {
    const toasts = useToasts();
    const shell = useShell();
    const [choosing, setChoosing] = useState<string | null>(null);

    const choose = (language: string) => {
        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.put(
            `${LANGUAGE_URL}/${language}`,
            {},
            {
                onStart: () => {
                    setChoosing(language);
                    owned.onStart();
                },
                onFinish: () => {
                    owned.onFinish();
                    setChoosing(null);
                },
            },
        );
    };

    return [choosing, choose];
}

function useTheme(): [Theme, () => void] {
    const [theme, setTheme] = useState<Theme>(() => readTheme(localStorageOf));

    useEffect(() => {
        applyTheme(theme, document.documentElement);
        writeTheme(theme, localStorageOf);
    }, [theme]);

    return [theme, () => setTheme((current) => (current === 'dark' ? 'light' : 'dark'))];
}

/** The top bar's one control, rendered for manageBookings alone; it opens the wizard with no prefill. */
function NewBookingButton() {
    const { open } = useNewBooking();
    const shell = useShell();

    return (
        <Button variant="primary" className="admin-new-booking" onClick={() => open()}>
            {shell.text('top_bar.new_booking')}
        </Button>
    );
}

export function AdminLayout({ children }: { children: ReactNode }) {
    const page = usePage<SharedProps>();
    const [theme, toggleTheme] = useTheme();
    const [signingOut, setSigningOut] = useState(false);
    const [ending, setEnding] = useState(false);
    const [choosing, choose] = useLanguageChoice();
    const languages = page.props.languages;
    useDocumentLanguage(page.props.language);
    useColourTheme(page.props.theme);

    const signOut = () => {
        router.post(LOGOUT_URL, {}, { onStart: () => setSigningOut(true), onFinish: () => setSigningOut(false) });
    };

    const endSession = () => {
        router.post(LOGOUT_URL, {}, { onStart: () => setEnding(true), onFinish: () => setEnding(false) });
    };

    return (
        <NewBookingProvider props={page.props}>
            <FlashToast flash={page.props.flash} />
            <Shell
                route={page.url}
                nav={page.props.nav}
                wordmark={<Wordmark name={page.props.brand.name} small />}
                business={page.props.tenant.name}
                userName={page.props.auth.user?.name ?? ''}
                theme={theme}
                onToggleTheme={toggleTheme}
                signingOut={signingOut}
                onSignOut={signOut}
                actions={page.props.permissions.includes('manageBookings') ? <NewBookingButton /> : undefined}
                language={languages === undefined ? undefined : <LanguageControl choices={languages} current={page.props.language} choosing={choosing} onChoose={choose} />}
                banner={
                    <>
                        <SupportBanner support={page.props.support ?? null} clock={clockOf(page.props)} ending={ending} onEnd={endSession} />
                        <NarrowedBanner narrowed={page.props.narrowed} manageBilling={page.props.permissions.includes('manageBilling')} free={page.props.free} />
                    </>
                }
            >
                {children}
            </Shell>
        </NewBookingProvider>
    );
}

/**
 * A page outside every shell: the flash as a toast, and the page inside the brand's scope where
 * it is the platform's own, the signup's and the console's door, and in the admin's theme alone
 * where it is a tenant's door.
 */
function GuestLayout({ branded, children }: { branded: boolean; children: ReactNode }) {
    // The sign-in page answered past its limit is rendered before the shared props are, so each
    // is read as possibly absent here.
    const props: Partial<GuestProps> = usePage().props;
    useDocumentLanguage(props.language);

    return (
        <>
            <FlashToast flash={props.flash ?? null} />
            {branded ? <div className={BRAND_SCOPE}>{children}</div> : children}
        </>
    );
}

/*
 * The console's shell: the admin's shell in the brand's scope with the console's wordmark in the
 * top bar and, beside it, the line naming the console's zone, the one place a console screen names
 * the clock its instants are spelled on; the rail the server hands as nav, rendered verbatim as
 * the admin's is, and the flash as a toast. The operator and the Sign out control render once the
 * operator prop names one; while it is null nothing posts to a sign-out. The theme switch is the
 * admin's.
 */
export function ConsoleLayout({ children }: { children: ReactNode }) {
    const page = usePage<ConsoleProps>();
    const [theme, toggleTheme] = useTheme();
    const [signingOut, setSigningOut] = useState(false);
    const operator = page.props.operator;

    const signOut = () => {
        router.post(CONSOLE_LOGOUT_URL, {}, { onStart: () => setSigningOut(true), onFinish: () => setSigningOut(false) });
    };

    return (
        <>
            <FlashToast flash={page.props.flash} />
            <Shell
                className={BRAND_SCOPE}
                route={page.url}
                nav={page.props.nav}
                business={<ConsoleWordmark name={page.props.brand.name} />}
                userName={operator ?? ''}
                theme={theme}
                onToggleTheme={toggleTheme}
                signingOut={signingOut}
                onSignOut={operator === null ? undefined : signOut}
                actions={<p className="admin-console-zone text-sm text-muted-foreground">{consoleZoneLine(page.props.zone)}</p>}
            >
                {children}
            </Shell>
        </>
    );
}

/**
 * The frame a page renders in, by its name: the admin's door alone, the signup's pages and the
 * console's door alone in the brand's scope, every other console page inside the console's shell,
 * and every other page inside the admin's.
 */
export function pageLayout(name: string): (page: ReactNode) => ReactNode {
    if (name === CONSOLE_DOOR || name.startsWith('signup/')) {
        return (page) => <GuestLayout branded>{page}</GuestLayout>;
    }

    if (name.startsWith('auth/')) {
        return (page) => <GuestLayout branded={false}>{page}</GuestLayout>;
    }

    if (name.startsWith('console/')) {
        return (page) => <ConsoleLayout>{page}</ConsoleLayout>;
    }

    return (page) => <AdminLayout>{page}</AdminLayout>;
}
