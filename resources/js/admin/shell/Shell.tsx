import { type MouseEvent, type ReactNode, type RefObject, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { useWide } from '@/lib/viewport';
import type { Theme } from '../theme';
import type { NavEntry } from '../types';
import { Controls } from './Controls';
import { FirstRowProvider } from './first-row';
import { Rail } from './Rail';
import { RouteBoundary } from './RouteBoundary';
import { BusinessChip, Sidebar } from './Sidebar';

/*
 * The shell: the sidebar and the page. There is no bar across the top: the page's own header is
 * its first row, and the shell hands it the controls, the language, the theme, the user's name
 * and Sign out, and its own button, last, through context. From the wide breakpoint the sidebar
 * stands beside the page; below it the sidebar goes, the menu waits in a drawer behind a button
 * the shell puts at the start of the first row, and the page's padding narrows. The shell is a
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

/** The menu's button and the drawer's title, below the wide breakpoint, a key of the shell's section. */
export const MENU_LABEL = 'nav.menu';

/*
 * Below the wide breakpoint the menu is a drawer on the modal primitive behind a button first in
 * the page's first row: the brand and the business's chip over the same list with the same current
 * entry, closing on a choice, on Escape and on the backdrop as a modal does, and the modal hands
 * focus back to the button. The drawer and whether it is open belong to the shell, which outlives
 * the page, and the button to the page's first row, which the page renders: a choice of another
 * screen replaces the page and its button with it, so the next page's button takes the focus as it
 * mounts. A press with Ctrl, Cmd, Shift or Alt held, or with another button, is the browser's: it
 * opens the entry elsewhere and this tab stays on its page, so no next button is owed the focus.
 * The drawer is mounted below the breakpoint alone and is closed as the viewport widens
 * past it, so it comes back closed. The button carries the pending count the menu's entry carries,
 * read off the same nav prop, so a request waiting is seen with the drawer closed; the server sends
 * none for none, so the button shows no count reading zero.
 */
function MenuButton({ nav, open, onOpen, refocus }: { nav: NavEntry[]; open: boolean; onOpen: () => void; refocus: RefObject<boolean> }) {
    const shell = useShell();
    const button = useRef<HTMLButtonElement>(null);
    const pending = nav.find((entry) => entry.pending !== null)?.pending ?? null;

    useEffect(() => {
        if (refocus.current) {
            refocus.current = false;
            button.current?.focus();
        }
    }, [refocus]);

    return (
        <Button ref={button} className="admin-menu" aria-haspopup="dialog" aria-expanded={open} onClick={onOpen}>
            {shell.text(MENU_LABEL)}
            {pending !== null && <span className="admin-menu-pending rounded-full bg-primary px-2 text-xs text-primary-foreground">{pending}</span>}
        </Button>
    );
}

type DrawerProps = { nav: NavEntry[]; brand: ReactNode; business: string | null; open: boolean; onClose: () => void; onChoose: (entry: NavEntry, press: MouseEvent) => void };

function RailDrawer({ nav, brand, business, open, onClose, onChoose }: DrawerProps) {
    const shell = useShell();

    return (
        <Modal open={open} title={shell.text(MENU_LABEL)} variant="drawer" onClose={onClose}>
            <div className="flex flex-col gap-3.5">
                <div className="admin-drawer__head flex min-h-11 items-center">{brand}</div>
                {business !== null && <BusinessChip name={business} />}
                <Rail nav={nav} onChoose={onChoose} />
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

    const row = {
        start: wide ? null : <MenuButton nav={nav} open={open} onOpen={() => setOpen(true)} refocus={refocus} />,
        controls: <Controls userName={userName} theme={theme} onToggleTheme={onToggleTheme} signingOut={signingOut} onSignOut={onSignOut} language={language} />,
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
            {!wide && <RailDrawer nav={nav} brand={brand(false)} business={business} open={open} onClose={close} onChoose={choose} />}
        </div>
    );
}
