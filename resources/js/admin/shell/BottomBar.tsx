import { Link } from '@inertiajs/react';
import { Menu as MenuIcon } from 'lucide-react';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import type { NavEntry } from '../types';
import { MENU_LABEL } from './MenuButton';
import { navIcon } from './icons';
import { Pending, RAIL_LABEL } from './Rail';
import { useHiddenOnScroll } from './scroll';

/*
 * The bar along the foot of the screen below the wide breakpoint, where a thumb rests: the menu's
 * first group, read verbatim off the nav prop as the rail reads it, four entries at most, each an
 * icon in a pill over its label, the current one's pill filled with the theme colour, and last a
 * Menu button that opens the drawer holding the whole menu. A first group of fewer than two
 * entries gives no bar: one destination beside the button is no choice, and the first row's Menu
 * button reaches it. The count an entry carries rides at its pill's corner, so a request waiting
 * is seen without opening anything.
 *
 * The bar is a rounded card floating 12 pixels off the screen's edges, above the phone's home
 * indicator where one takes the foot of the screen. It slides away below the screen while the
 * page scrolls down and comes back on a scroll up, near the page's end and on a page too short to
 * scroll; one that holds the keyboard's focus stays, so a keyboard never tabs into a bar it cannot
 * see. The focus a press leaves on an entry or on the Menu button holds nothing up: the pressed
 * entry keeps it on the screen it led to, and the bar slides away there as anywhere. The
 * stylesheet keeps the page's other floating parts clear of it, and puts it away while a dialog
 * or the help panel filling a phone is up.
 */

/** The most entries the bar holds before its Menu button. */
const MOST = 4;

/** The menu's first group, four entries at most; none where that group holds fewer than two. */
export function barEntries(nav: NavEntry[]): NavEntry[] {
    const first = nav[0]?.group;
    const group = nav.filter((entry) => entry.group === first);

    return group.length < 2 ? [] : group.slice(0, MOST);
}

const ITEM = 'focus-ring flex w-full min-w-0 flex-col items-center gap-0.5 rounded-3xl px-0.5 pt-1.5 pb-1 font-semibold text-muted-foreground outline-none hover:text-foreground';

const PILL = 'admin-bottom-bar__icon relative flex h-8 w-13 items-center justify-center rounded-full motion-safe:transition-colors motion-safe:duration-200';

const LABEL = 'admin-bottom-bar__label max-w-full truncate text-[10.5px] leading-tight';

type Props = { nav: NavEntry[]; open: boolean; onMenu: () => void };

export function BottomBar({ nav, open, onMenu }: Props) {
    const shell = useShell();
    const away = useHiddenOnScroll();
    const entries = barEntries(nav);

    if (entries.length === 0) {
        return null;
    }

    return (
        <nav
            aria-label={shell.text(RAIL_LABEL)}
            className={cn(
                'admin-bottom-bar fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-40 mx-auto max-w-120 rounded-shell bg-card p-1.5 text-card-foreground shadow-dock motion-safe:transition-[translate] motion-safe:duration-400 motion-safe:ease-soft',
                away && 'admin-bottom-bar--away translate-y-[150%] has-[:focus-visible]:translate-y-0',
            )}
        >
            <ul className="grid auto-cols-fr grid-flow-col">
                {entries.map((entry) => {
                    const Icon = navIcon(entry.key);

                    return (
                        <li key={entry.key} className="flex min-w-0">
                            <Link href={entry.url} aria-current={entry.current ? 'page' : undefined} className={cn('admin-bottom-bar__entry group/item', ITEM, 'aria-[current=page]:text-foreground')}>
                                <span className={cn(PILL, entry.current ? 'bg-primary text-primary-foreground' : 'group-hover/item:bg-muted')}>
                                    <Icon className="size-4.5" aria-hidden="true" />
                                    {entry.pending !== null && <Pending count={entry.pending} corner className="-top-1.5 right-0 h-4.75 min-w-4.75 px-1.25" />}
                                </span>
                                <span className={LABEL}>{entry.label}</span>
                            </Link>
                        </li>
                    );
                })}
                <li className="flex min-w-0">
                    <button type="button" className={cn('admin-bottom-bar__menu group/item', ITEM)} aria-haspopup="dialog" aria-expanded={open} onClick={onMenu}>
                        <span className={cn(PILL, 'group-hover/item:bg-muted')}>
                            <MenuIcon className="size-4.5" aria-hidden="true" />
                        </span>
                        <span className={LABEL}>{shell.text(MENU_LABEL)}</span>
                    </button>
                </li>
            </ul>
        </nav>
    );
}
