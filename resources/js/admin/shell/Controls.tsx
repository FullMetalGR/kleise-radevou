import type { ReactNode } from 'react';
import { Moon, SignOut, Sun } from '@/components/glyphs';
import { Button } from '@/components/ui/button';
import { useShell } from '@/lib/strings';
import type { Theme } from '../theme';

/*
 * The top bar's controls at its end, one group: the language control where one is handed, then
 * the light and dark switch, a round icon button showing the moon while the page is light and the
 * sun while it is dark, named for the theme it switches to; then the signed-in user's name, cut
 * short with an ellipsis where it runs long and shown whole in its tooltip, and Sign out with its
 * icon. Signing out is an action like any other: it shows a busy state until the server answers,
 * and the answer arrives as the next page. Where nobody is signed in, neither the name nor Sign out
 * renders.
 *
 * At the desktop width the group is one row. Below it the group wraps: a row that cannot wrap is
 * as wide as its controls together, which is wider than a phone, and would push the page sideways.
 */

type Props = {
    userName: string;
    theme: Theme;
    onToggleTheme: () => void;
    signingOut: boolean;
    /** Absent where nobody is signed in, and with it the name and the Sign out control. */
    onSignOut?: (() => void) | undefined;
    /** The language control, first in the row; absent where none is offered. */
    language?: ReactNode;
};

export function Controls({ userName, theme, onToggleTheme, signingOut, onSignOut, language }: Props) {
    const shell = useShell();

    return (
        <div className="admin-controls flex items-center gap-2 max-desktop:flex-wrap">
            {language}
            <Button variant="tertiary" iconOnly icon={theme === 'dark' ? <Sun /> : <Moon />} className="admin-theme" onClick={onToggleTheme}>
                {shell.text(theme === 'dark' ? 'top_bar.light' : 'top_bar.dark')}
            </Button>
            {onSignOut !== undefined && (
                <>
                    <span className="admin-user max-w-40 truncate text-sm text-muted-foreground" title={userName}>
                        {userName}
                    </span>
                    <Button variant="tertiary" className="admin-signout" icon={<SignOut />} busy={signingOut} busyLabel={shell.text('top_bar.signing_out')} onClick={onSignOut}>
                        {shell.text('top_bar.sign_out')}
                    </Button>
                </>
            )}
        </div>
    );
}
