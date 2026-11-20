import { Link } from '@inertiajs/react';
import type { MouseEvent, ReactNode } from 'react';
import { Tooltip } from '@/components/ui/tooltip';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import type { NavEntry } from '../types';
import { navIcon } from './icons';

/*
 * The rail, rendered verbatim from the nav prop. The server has already filtered it by permission
 * and entitlement and marked the current entry, and the client adds no second gate: it reads the
 * entries and nothing else, never the role and never the permissions. Groups are headings only
 * when more than one exists, so a one-entry rail has none. In the drawer a press on an entry, the
 * current one included, is handed to onChoose with the entry and the press itself, which says
 * whether a key was held, and closes the drawer. The entries' labels
 * and groups arrive in the page's language; the rail's own name is the shell's. Each entry's icon
 * is chosen by the one word the server names it by, the same word that keys its row, as two
 * entries may share an address; the icon is decoration beside the label, hidden from assistive
 * technology.
 *
 * Each entry is a pill: the icon in a circle, then the label. The current entry is tinted and its
 * circle filled with the theme colour; a pointer over any entry tints it too. The Bookings count
 * is an amber badge at the pill's end, named to a screen reader for what it counts, since the
 * figure alone says nothing. Collapsed, in the sidebar's strip of icons, the labels stay for a
 * screen reader alone and show in a tooltip on hover and on focus, the group labels give way to
 * rules between the groups, and the badge moves to the circle's corner, smaller. Every entry wears
 * the tooltip, held shut while its label shows, so an entry is the same element folded and open
 * and one that holds the focus keeps it.
 */

function groupsOf(nav: NavEntry[]): { group: string; entries: NavEntry[] }[] {
    const groups: { group: string; entries: NavEntry[] }[] = [];

    for (const entry of nav) {
        const found = groups.find((candidate) => candidate.group === entry.group);

        if (found === undefined) {
            groups.push({ group: entry.group, entries: [entry] });
        } else {
            found.entries.push(entry);
        }
    }

    return groups;
}

/** The rail's name, a key of the shell's section. */
export const RAIL_LABEL = 'nav.main';

export function Rail({ nav, onChoose, collapsed = false }: { nav: NavEntry[]; onChoose?: (entry: NavEntry, press: MouseEvent) => void; collapsed?: boolean }) {
    const shell = useShell();
    const groups = groupsOf(nav);
    const headed = groups.length > 1;

    return (
        <nav className={cn('admin-rail flex flex-col', collapsed ? 'gap-2' : 'gap-2.5')} aria-label={shell.text(RAIL_LABEL)}>
            {groups.map(({ group, entries }, index) => (
                <div key={group} className={cn('flex flex-col', collapsed && index > 0 && 'border-t pt-2')}>
                    {headed && <h2 className={cn('admin-rail-group px-3 pt-1.5 pb-2 text-eyebrow text-muted-foreground uppercase', collapsed && 'sr-only')}>{group}</h2>}
                    <ul className="flex flex-col gap-1">
                        {entries.map((entry) => {
                            const Icon = navIcon(entry.key);
                            const link = (
                                <Link
                                    href={entry.url}
                                    aria-current={entry.current ? 'page' : undefined}
                                    onClick={(press) => onChoose?.(entry, press)}
                                    className={cn(
                                        'admin-rail-entry group/entry focus-ring relative flex min-h-12 items-center gap-3 rounded-full py-1.5 ps-1.5 pe-2.5 text-body font-semibold text-muted-foreground hover:bg-muted hover:text-foreground aria-[current=page]:bg-muted aria-[current=page]:text-foreground',
                                        collapsed && 'justify-center pe-1.5',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'admin-rail-entry__icon flex size-9 shrink-0 items-center justify-center rounded-full motion-safe:transition-transform motion-safe:group-hover/entry:scale-106',
                                            entry.current && 'bg-primary text-primary-foreground',
                                        )}
                                        aria-hidden="true"
                                    >
                                        <Icon className="size-4.5" />
                                    </span>
                                    <span className={cn('admin-rail-entry__label min-w-0 flex-1 truncate', collapsed && 'sr-only')}>{entry.label}</span>
                                    {entry.pending !== null && <Pending count={entry.pending} corner={collapsed} />}
                                </Link>
                            );

                            return (
                                <li key={entry.key}>
                                    <Tooltip label={entry.label} disabled={!collapsed}>
                                        {link}
                                    </Tooltip>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

/**
 * The count an entry carries, the amber badge: at the pill's end beside the label, or at the
 * circle's corner in the strip of icons, ringed in the card's colour so it stands off the circle.
 * Its name says what the figure counts, and the figure is its only text. The bottom bar and the
 * Menu button wear the corner's badge too, each placing it with a class of its own.
 */
export function Pending({ count, corner, className }: { count: number; corner: boolean; className?: string }): ReactNode {
    const shell = useShell();

    return (
        <span
            role="img"
            aria-label={shell.choice('nav.pending', count)}
            className={cn(
                'admin-rail-pending flex items-center justify-center rounded-full bg-attention-soft-strong font-bold text-attention-ink tabular-nums',
                corner ? 'absolute -top-0.5 right-1 h-5 min-w-5 border-2 border-card px-1 text-[11px] leading-none' : 'h-6 min-w-6 px-1.75 text-xs ring-1 ring-attention-line',
                className,
            )}
        >
            {count}
        </span>
    );
}
