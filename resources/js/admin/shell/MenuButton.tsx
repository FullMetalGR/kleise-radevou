import type { Ref } from 'react';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { Pending } from './Rail';

/*
 * The round Menu button below the wide breakpoint: three short bars in a 48 pixel circle, outlined
 * as a secondary button while the drawer is shut and filled with the theme colour while it is
 * open, when the bars turn into a cross, the top one dropping and the bottom one rising onto the
 * middle as both turn and the middle one fading out. The bars are drawn, not read: the button's
 * name is its only text, Menu while the drawer is shut and Close while it is open, and it says it
 * opens a dialog and whether that dialog is up. The first row carries one and the drawer's head
 * its twin, so the drawer closes from where it was opened. A count rides at the circle's corner
 * where one is handed.
 */

/** The menu's button and the drawer's title, below the wide breakpoint, a key of the shell's section. */
export const MENU_LABEL = 'nav.menu';

/** The same button's name while the drawer is open and a press closes it. */
const CLOSE_LABEL = 'nav.close';

const BAR = 'admin-menu__bar block h-0.5 w-4.5 rounded-full bg-current motion-safe:transition-[translate,rotate,scale,opacity] motion-safe:duration-350 motion-safe:ease-soft';

type Props = {
    open: boolean;
    pending: number | null;
    onClick: () => void;
    ref?: Ref<HTMLButtonElement>;
};

export function MenuButton({ open, pending, onClick, ref }: Props) {
    const shell = useShell();

    return (
        <button
            ref={ref}
            type="button"
            className={cn(
                'admin-menu focus-ring relative inline-flex size-12 shrink-0 flex-col items-center justify-center gap-0.75 rounded-full outline-none motion-safe:transition motion-safe:duration-200 motion-safe:ease-soft',
                open ? 'bg-primary text-primary-foreground shadow-glow' : 'bg-card text-foreground inset-ring-[1.5px] inset-ring-border hover:bg-muted hover:inset-ring-ink-tertiary',
            )}
            aria-haspopup="dialog"
            aria-expanded={open}
            data-open={open ? 'true' : 'false'}
            onClick={onClick}
        >
            <span className="sr-only">{shell.text(open ? CLOSE_LABEL : MENU_LABEL)}</span>
            <span className={cn(BAR, open && 'translate-y-1.25 rotate-45')} aria-hidden="true" />
            <span className={cn(BAR, open && 'scale-x-0 opacity-0')} aria-hidden="true" />
            <span className={cn(BAR, open && '-translate-y-1.25 -rotate-45')} aria-hidden="true" />
            {pending !== null && <Pending count={pending} corner className="admin-menu-pending -top-1 -right-1" />}
        </button>
    );
}
