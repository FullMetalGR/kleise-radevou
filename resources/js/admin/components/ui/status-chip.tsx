import type { ReactNode } from 'react';
import { formatCount } from '@/lib/format';
import { placed, type ShellKey, type ShellWords, useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';

/*
 * The status chip. Nine statuses cannot be separated by hue alone, so a chip carries its family in
 * its shape first, with colour agreeing: live for confirmed; attention for awaiting approval, the
 * only state blocked on the operator; provisional, dashed, for the two other held states; void for
 * cancelled, rejected and no-show; past for completed and expired. An unrecognised value renders
 * the base chip with the raw value as its label: the raw value reads as data where a guess would
 * read as a bug. The shapes themselves are drawn in app.css.
 *
 * A booking status's label and its line with a count are keys of the shell's section, read in the
 * page's language through the strings the chip is given: the admin's in the user's language, the
 * console's in English. A catalog row's two states keep their shapes here and take their word from
 * the caller, the screen that names the row.
 */

export type ChipShape = 'live' | 'attention' | 'provisional' | 'void' | 'past';

/** A booking status's chip: its shape, its word and its line with a count in the shell's kit. */
type BookingChip = { readonly shape: ChipShape; readonly label: ShellKey; readonly counted: ShellKey };

/** A catalog row's state: its shape alone, the word the caller's own screen holds. */
type CatalogChip = { readonly shape: ChipShape };

const STATUSES: Readonly<Record<string, BookingChip | CatalogChip>> = {
    confirmed: { shape: 'live', label: 'kit.statuses.confirmed', counted: 'kit.counted.confirmed' },
    awaiting_approval: { shape: 'attention', label: 'kit.statuses.awaiting_approval', counted: 'kit.counted.awaiting_approval' },
    pending: { shape: 'provisional', label: 'kit.statuses.pending', counted: 'kit.counted.pending' },
    awaiting_payment: { shape: 'provisional', label: 'kit.statuses.awaiting_payment', counted: 'kit.counted.awaiting_payment' },
    cancelled: { shape: 'void', label: 'kit.statuses.cancelled', counted: 'kit.counted.cancelled' },
    rejected: { shape: 'void', label: 'kit.statuses.rejected', counted: 'kit.counted.rejected' },
    no_show: { shape: 'void', label: 'kit.statuses.no_show', counted: 'kit.counted.no_show' },
    completed: { shape: 'past', label: 'kit.statuses.completed', counted: 'kit.counted.completed' },
    expired: { shape: 'past', label: 'kit.statuses.expired', counted: 'kit.counted.expired' },
    // A catalog row's two states: offered, or resting and not offered. A service and a member of
    // the team are nouns of two genders in Greek, so the word is the caller's.
    active: { shape: 'live' },
    inactive: { shape: 'past' },
};

function knownOf(status: string): BookingChip | CatalogChip | undefined {
    return Object.hasOwn(STATUSES, status) ? STATUSES[status] : undefined;
}

/** A status's chip family, the shape its chip is drawn in, or null for a value the chip does not know. */
export function chipShapeOf(status: string): ChipShape | null {
    return knownOf(status)?.shape ?? null;
}

/**
 * A count, where given, makes the compact chip the month overview draws per status present on a
 * day, and Home's awaiting feed its own, in the same shape. A booking status's chip reads its
 * whole line of the kit's counted group, chosen by the count, since Greek bends the word by the
 * number: the figure, spelled by formatCount in the shell's locale, stands where the line's :count
 * does, in its own span, and the words in theirs. A status the chip does not know keeps the figure
 * before its raw value, and a catalog row's state (active, inactive) given a count does as that
 * status does, the figure before its label.
 */
export function StatusChip({ status, count, label: given, className }: { status: string; count?: number; label?: string; className?: string }) {
    const shell = useShell();
    const known = knownOf(status);
    const label = given ?? statusLabel(status, shell);

    return (
        <span className={cn('admin-chip', known !== undefined && `admin-chip--${known.shape}`, count !== undefined && 'admin-chip--compact', className)} data-status={status}>
            {count === undefined ? label : counted(count, known !== undefined && 'counted' in known ? shell.choice(known.counted, count, { count: ':count' }) : `:count ${label}`, shell.locale)}
        </span>
    );
}

/**
 * A chip's line with its count placed: the figure in .admin-chip__count, each run of words in
 * .admin-chip__label, the spaces around a run left outside it.
 */
function counted(count: number, line: string, locale: string): ReactNode[] {
    const figure = <span key="count" className="admin-chip__count">{formatCount(count, locale)}</span>;

    return placed(line, { count: figure }, (words) => {
        const match = /^(\s*)(.*?)(\s*)$/s.exec(words);
        const inner = match?.[2] ?? '';

        return inner === '' ? words : (
            <>
                {match?.[1]}
                <span className="admin-chip__label">{inner}</span>
                {match?.[3]}
            </>
        );
    });
}

/**
 * A booking status in words, as its chip labels it, in the strings' language. Any other value is
 * itself: an unrecognised one, and a catalog row's active or inactive, whose word is its screen's.
 */
export function statusLabel(status: string, shell: ShellWords): string {
    const known = knownOf(status);

    return known === undefined || !('label' in known) ? status : shell.text(known.label);
}
