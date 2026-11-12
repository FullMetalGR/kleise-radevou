import { Link } from '@inertiajs/react';
import { useShell } from '@/lib/strings';
import type { NavEntry } from '../types';

/*
 * The rail, rendered verbatim from the nav prop. The server has already filtered it by permission
 * and entitlement and marked the current entry, and the client adds no second gate: it reads the
 * entries and nothing else, never the role and never the permissions. Groups are headings only
 * when more than one exists, so a one-entry rail has none. In the drawer of 1.6 a press on an
 * entry, the current one included, is handed to onChoose, which closes it. The entries' labels
 * and groups arrive in the page's language; the rail's own name is the shell's.
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

export function Rail({ nav, onChoose }: { nav: NavEntry[]; onChoose?: () => void }) {
    const shell = useShell();
    const groups = groupsOf(nav);
    const headed = groups.length > 1;

    return (
        <nav className="admin-rail flex flex-col gap-4" aria-label={shell.text(RAIL_LABEL)}>
            {groups.map(({ group, entries }) => (
                <div key={group} className="flex flex-col gap-1">
                    {headed && <h2 className="admin-rail-group px-2 text-xs font-semibold text-muted-foreground uppercase">{group}</h2>}
                    <ul className="flex flex-col gap-1">
                        {entries.map((entry) => (
                            <li key={entry.url}>
                                <Link
                                    href={entry.url}
                                    aria-current={entry.current ? 'page' : undefined}
                                    onClick={onChoose}
                                    className="admin-rail-entry flex items-center justify-between rounded-md px-2 py-1.5 text-sm hover:bg-muted aria-[current=page]:bg-muted aria-[current=page]:font-semibold"
                                >
                                    <span>{entry.label}</span>
                                    {entry.pending !== null && <span className="admin-rail-pending rounded-full bg-primary px-2 text-xs text-primary-foreground">{entry.pending}</span>}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </nav>
    );
}
