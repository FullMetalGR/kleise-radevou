import type { ReactNode } from 'react';
import type { Theme } from '../theme';
import { Controls } from './Controls';

/*
 * The top bar: the platform's mark beside the business's name, a slot for the page-independent
 * actions (the New booking button arrives there with the wizard), then the controls: the language
 * control, the theme switch, the user's name and Sign out. Below the admin's breakpoint, 1.6, the
 * rail's control comes first and the bar wraps rather than run off the screen: the group of
 * controls drops to a line of its own where the first has no room for it, and wraps within itself
 * where one line cannot hold it.
 *
 * The console's shell, reuses the bar with the console's wordmark in the business's place and no
 * mark beside it; while nobody is signed in there it passes no sign-out, and the bar names nobody
 * and renders no Sign out control.
 *
 * The language control, sits before the theme switch where the admin passes one: for a signed-in
 * user outside a support session. The console passes none, its copy staying English. The bar's
 * words are the shell's, in the page's language, the console's in English.
 */

type Props = {
    /** Before the business's name; absent where the business's place holds the wordmark itself. */
    wordmark?: ReactNode;
    business: ReactNode;
    userName: string;
    theme: Theme;
    onToggleTheme: () => void;
    signingOut: boolean;
    /** Absent where nobody is signed in, and with it the name and the Sign out control. */
    onSignOut?: (() => void) | undefined;
    actions?: ReactNode;
    /** The rail's control, first in the bar, below the breakpoint alone. */
    menu?: ReactNode;
    /** The language control, before the theme switch; absent where none is offered. */
    language?: ReactNode;
};

export function TopBar({ wordmark, business, userName, theme, onToggleTheme, signingOut, onSignOut, actions, menu, language }: Props) {
    return (
        <header className="admin-topbar flex items-center gap-3 border-b px-4 py-2 max-desktop:flex-wrap">
            {menu}
            {wordmark}
            <p className="admin-business font-semibold">{business}</p>
            <div className="admin-topbar-actions flex flex-1 items-center gap-2">{actions}</div>
            <Controls userName={userName} theme={theme} onToggleTheme={onToggleTheme} signingOut={signingOut} onSignOut={onSignOut} language={language} />
        </header>
    );
}
