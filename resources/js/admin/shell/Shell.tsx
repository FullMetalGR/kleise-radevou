import { type MouseEvent, type ReactNode, type RefObject, useEffect, useRef, useState } from 'react';
import { Modal } from '@/components/ui/modal';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { useWide } from '@/lib/viewport';
import type { Theme } from '../theme';
import type { NavEntry } from '../types';
import { BottomBar, barEntries } from './BottomBar';
import { Controls } from './Controls';
import { FirstRowProvider } from './first-row';
import { MENU_LABEL, MenuButton } from './MenuButton';
import { Rail } from './Rail';
import { RouteBoundary } from './RouteBoundary';
import { BusinessChip, Sidebar } from './Sidebar';

/*
 * The shell: the sidebar and the page. There is no bar across the top: the page's own header is
 * its first row, and the shell hands it its own button, last, through context, and from the wide
 * breakpoint the controls too, the language, the theme, the user's name and Sign out. From that
 * breakpoint the sidebar stands beside the page; below it the sidebar goes, the menu waits in a
 * drawer behind a round button the shell puts at the start of the first row, the controls move
 * into the drawer after the menu, the menu's first group stands in a bar along the foot of the
 * screen, and the page's padding narrows. The shell is a
 * size container, so the sidebar can ask how wide the shell is. The page renders inside an error
 * boundary keyed on the route, so a screen that throws takes itself down and nothing else, and
 * navigating anywhere mounts a fresh boundary. The key is the path alone, not the query string: a
 * filter is a query parameter of the same route, and a filter change must keep the page mounted,
 * with the focus in the search field and its in-flight state, where a new path is a new route and
 * mounts afresh. The shell paints its own ground and ink, so a brand scope on its root, the
 * console's, shows the page tint mixed from the brand's primary and not the body's.
 *
 * The first thing the keyboard reaches on every screen is a link past the menu to the page's
 * content, unseen until it takes the focus. A press hands the focus to the content
 * itself, which takes it from a script alone, and leaves the address as it was, so no hash lands
 * in the history or the router.
 */

function routeKey(url: string): string {
    return url.split(/[?#]/)[0] ?? url;
}

/*
 * Below the wide breakpoint the menu is a drawer on the modal primitive behind the round button
 * first in the page's first row, and behind the bottom bar's Menu button: at its head a twin of the
 * button, a cross while the drawer is open, beside the brand, then the business's chip, the same
 * list with the same current entry, and last the controls stacked. It closes on a choice, on its
 * twin, on Escape and on the backdrop as a modal does, and the modal hands focus back to whichever
 * button opened it. The drawer and whether it is open belong to the shell, which outlives the page,
 * and the first row's button to the page's first row, which the page renders: a choice of another
 * screen replaces the page and its button with it, so the next page's button takes the focus as it
 * mounts. A press with Ctrl, Cmd, Shift or Alt held, or with another button, is the browser's: it
 * opens the entry elsewhere and this tab stays on its page, so no next button is owed the focus.
 * The drawer is mounted below the breakpoint alone and is closed as the viewport widens past it,
 * so it comes back closed.
 *
 * The pending count the menu's entry carries is read off the same nav prop and shown where the
 * entry is seen with the drawer closed: on the bottom bar's entry where the bar holds it, and on
 * the first row's button where the bar does not, so it is never shown twice. The server sends
 * none for none, so nothing shows a count reading zero.
 */
function FirstRowMenu({ nav, open, onOpen, refocus }: { nav: NavEntry[]; open: boolean; onOpen: () => void; refocus: RefObject<boolean> }) {
    const button = useRef<HTMLButtonElement>(null);
    const shown = new Set(barEntries(nav).map((entry) => entry.key));
    const pending = nav.find((entry) => entry.pending !== null && !shown.has(entry.key))?.pending ?? null;

    useEffect(() => {
        if (refocus.current) {
            refocus.current = false;
            button.current?.focus();
        }
    }, [refocus]);

    return <MenuButton ref={button} open={open} pending={pending} onClick={onOpen} />;
}

type DrawerProps = {
    nav: NavEntry[];
    brand: ReactNode;
    business: string | null;
    controls: ReactNode;
    open: boolean;
    onClose: () => void;
    onChoose: (entry: NavEntry, press: MouseEvent) => void;
};

function MenuDrawer({ nav, brand, business, controls, open, onClose, onChoose }: DrawerProps) {
    const shell = useShell();

    return (
        <Modal open={open} title={shell.text(MENU_LABEL)} variant="drawer" onClose={onClose}>
            <div className="flex min-h-full flex-col gap-3.5">
                <div className="admin-drawer__head flex min-h-12 min-w-0 items-center gap-3">
                    <MenuButton open pending={null} onClick={onClose} />
                    <div className="min-w-0">{brand}</div>
                </div>
                {business !== null && <BusinessChip name={business} />}
                <Rail nav={nav} onChoose={onChoose} />
                <div className="admin-drawer__controls mt-auto border-t pt-3.5">{controls}</div>
            </div>
        </Modal>
    );
}

type Props = {
    route: string;
    nav: NavEntry[];
    /** The brand at the sidebar's head, given whether the sidebar is folded. */
    brand: (collapsed: boolean) => ReactNode;
    /** The business's name for the sidebar's chip; none where there is no business, as on the console. */
    business: string | null;
    userName: string;
    theme: Theme;
    onToggleTheme: () => void;
    signingOut: boolean;
    /** Absent where nobody is signed in: the first row then names nobody and offers no sign-out. */
    onSignOut?: (() => void) | undefined;
    /** The shell's own button, last in the first row, given whether the page has a main button of its own. */
    action?: (quiet: boolean) => ReactNode;
    /** The first row's language control, where one is offered. */
    language?: ReactNode;
    /** Above the page content and outside its boundary, so a page that throws keeps it. */
    banner?: ReactNode;
    /** A class of the shell's own beside admin-shell: the console's brand scope. */
    className?: string;
    children: ReactNode;
};

/** The content's id, the skip link's target. */
const CONTENT = 'admin-main';

export function Shell({ route, nav, brand, business, userName, theme, onToggleTheme, signingOut, onSignOut, action, language, banner, className, children }: Props) {
    const shell = useShell();
    const wide = useWide();
    const [open, setOpen] = useState(false);
    const refocus = useRef(false);

    if (wide && open) {
        setOpen(false);
    }

    const close = () => setOpen(false);

    // Only a choice that takes this tab to another path replaces the page; the current screen
    // keeps its button, and so does a press the browser opens elsewhere.
    const choose = (entry: NavEntry, press: MouseEvent) => {
        const elsewhere = press.metaKey || press.ctrlKey || press.shiftKey || press.altKey || press.button !== 0;

        refocus.current = !elsewhere && routeKey(entry.url) !== routeKey(route);
        close();
    };

    const controls = (stacked: boolean) => (
        <Controls stacked={stacked} userName={userName} theme={theme} onToggleTheme={onToggleTheme} signingOut={signingOut} onSignOut={onSignOut} language={language} />
    );

    const row = {
        start: wide ? null : <FirstRowMenu nav={nav} open={open} onOpen={() => setOpen(true)} refocus={refocus} />,
        controls: wide ? controls(false) : null,
        action: action ?? null,
    };

    return (
        <div className={cn('admin-shell @container flex min-h-screen bg-background text-foreground', className)}>
            <a
                href={`#${CONTENT}`}
                className="admin-skip focus-ring sr-only rounded-full bg-foreground font-bold text-background focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[55] focus:px-4 focus:py-2"
                onClick={(event) => {
                    event.preventDefault();
                    document.getElementById(CONTENT)?.focus();
                }}
            >
                {shell.text('nav.skip')}
            </a>
            {wide && <Sidebar nav={nav} brand={brand} business={business} />}
            <FirstRowProvider value={row}>
                <main
                    id={CONTENT}
                    tabIndex={-1}
                    className="admin-page min-w-0 flex-1 px-4 pb-4 outline-none desktop:px-6 desktop:pb-6 wide:ps-7 wide:pe-9 wide:pb-16 [&>.admin-narrowed:first-child]:mt-4 [&>.admin-support-banner]:mt-4"
                >
                    {banner}
                    <RouteBoundary key={routeKey(route)}>{children}</RouteBoundary>
                </main>
            </FirstRowProvider>
            {!wide && (
                <>
                    <BottomBar nav={nav} open={open} onMenu={() => setOpen(true)} />
                    <MenuDrawer nav={nav} brand={brand(false)} business={business} controls={controls(true)} open={open} onClose={close} onChoose={choose} />
                </>
            )}
        </div>
    );
}
