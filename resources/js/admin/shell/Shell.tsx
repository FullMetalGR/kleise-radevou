import { type ReactNode, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { useDesktop } from '@/lib/viewport';
import type { Theme } from '../theme';
import type { NavEntry } from '../types';
import { Rail } from './Rail';
import { RouteBoundary } from './RouteBoundary';
import { TopBar } from './TopBar';

/*
 * The shell: the rail, the top bar and the page; below the admin's breakpoint the rail behind a
 * control in the top bar and the page's padding narrower. The page renders inside an error
 * boundary keyed on the route, so a screen that throws takes itself down and nothing else, and
 * navigating anywhere mounts a fresh boundary. The key is the path alone, not the query string: a
 * filter is a query parameter of the same route, and a filter change must keep the page mounted,
 * with the focus in the search field and its in-flight state, where a new path is a new route and
 * mounts afresh.
 */

function routeKey(url: string): string {
    return url.split(/[?#]/)[0] ?? url;
}

/** The rail's control and the drawer's title, below the breakpoint, a key of the shell's section. */
export const MENU_LABEL = 'nav.menu';

/*
 * Below the admin's breakpoint, 1.6, the rail is a drawer on the modal primitive behind a control
 * first in the top bar: the same list with the same current entry, closing on a choice, on
 * Escape and on the backdrop as a modal does, and the modal hands focus back to the control. It
 * is mounted below the breakpoint alone, so a drawer left open as the viewport widens past it is
 * gone with it, and comes back closed. The control carries the pending count the rail's entry
 * carries, read off the same nav prop, so a request waiting is seen with the drawer closed; the
 * server sends none for none, so the control shows no count reading zero.
 */
function RailDrawer({ nav }: { nav: NavEntry[] }) {
    const shell = useShell();
    const [open, setOpen] = useState(false);
    const close = () => setOpen(false);
    const pending = nav.find((entry) => entry.pending !== null)?.pending ?? null;

    return (
        <>
            <Button className="admin-menu" aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen(true)}>
                {shell.text(MENU_LABEL)}
                {pending !== null && <span className="admin-menu-pending rounded-full bg-primary px-2 text-xs text-primary-foreground">{pending}</span>}
            </Button>
            <Modal open={open} title={shell.text(MENU_LABEL)} variant="drawer" onClose={close}>
                <Rail nav={nav} onChoose={close} />
            </Modal>
        </>
    );
}

type Props = {
    route: string;
    nav: NavEntry[];
    /** The top bar's wordmark before the business's name, where the business's place does not hold it. */
    wordmark?: ReactNode;
    business: ReactNode;
    userName: string;
    theme: Theme;
    onToggleTheme: () => void;
    signingOut: boolean;
    /** Absent where nobody is signed in: the top bar then names nobody and offers no sign-out. */
    onSignOut?: (() => void) | undefined;
    actions?: ReactNode;
    /** The top bar's language control, where one is offered. */
    language?: ReactNode;
    /** Above the page content and outside its boundary, so a page that throws keeps it. */
    banner?: ReactNode;
    /** A class of the shell's own beside admin-shell: the console's brand scope. */
    className?: string;
    children: ReactNode;
};

export function Shell({ route, nav, wordmark, business, userName, theme, onToggleTheme, signingOut, onSignOut, actions, language, banner, className, children }: Props) {
    const desktop = useDesktop();

    return (
        <div className={cn('admin-shell flex min-h-screen', className)}>
            {desktop && (
                <aside className="admin-aside w-56 shrink-0 border-r p-3">
                    <Rail nav={nav} />
                </aside>
            )}
            <div className="flex min-w-0 flex-1 flex-col">
                <TopBar
                    wordmark={wordmark}
                    business={business}
                    userName={userName}
                    theme={theme}
                    onToggleTheme={onToggleTheme}
                    signingOut={signingOut}
                    onSignOut={onSignOut}
                    actions={actions}
                    language={language}
                    menu={desktop ? undefined : <RailDrawer nav={nav} />}
                />
                <main className="admin-page flex-1 p-4 desktop:p-6">
                    {banner}
                    <RouteBoundary key={routeKey(route)}>{children}</RouteBoundary>
                </main>
            </div>
        </div>
    );
}
