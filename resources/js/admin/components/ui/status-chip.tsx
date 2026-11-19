import type { ComponentType, ReactNode } from 'react';
import { formatCount } from '@/lib/format';
import { placed, type ShellKey, type ShellWords, useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { ChipAlert, ChipCard, ChipClock, ChipCross, ChipHourglass, ChipTick, ChipTimer } from '../glyphs';

/*
 * The status chip. Nine statuses cannot be told apart by hue alone, so every chip carries an icon
 * before its label, and the four that ended without the visit strike the label through. Each
 * status belongs to a family: live for confirmed; attention for awaiting approval, the only state
 * blocked on the operator; provisional for the two other held states; void for cancelled, rejected
 * and no-show; past for completed and expired. The family is the chip's class and the calendar's
 * hue; the look is the status's own, drawn in app.css by its data-status, since a status can look
 * unlike its family (rejected is red, expired is the grey ring). An unrecognised value renders the
 * base chip with the raw value as its label and no icon: the raw value reads as data where a guess
 * would read as a bug.
 *
 * A booking status's label and its line with a count are keys of the shell's section, read in the
 * page's language through the strings the chip is given: the admin's in the user's language, the
 * console's in English. A catalog row's two states keep their shapes here and take their word from
 * the caller, the screen that names the row.
 */

export type ChipShape = 'live' | 'attention' | 'provisional' | 'void' | 'past';

/** A booking status's chip: its shape, its icon, its word and its line with a count in the shell's kit. */
type BookingChip = { readonly shape: ChipShape; readonly icon: ComponentType; readonly label: ShellKey; readonly counted: ShellKey };

/** A catalog row's state: its shape and its icon, if it has one, the word the caller's own screen holds. */
type CatalogChip = { readonly shape: ChipShape; readonly icon?: ComponentType };

const STATUSES: Readonly<Record<string, BookingChip | CatalogChip>> = {
    confirmed: { shape: 'live', icon: ChipTick, label: 'kit.statuses.confirmed', counted: 'kit.counted.confirmed' },
    awaiting_approval: { shape: 'attention', icon: ChipClock, label: 'kit.statuses.awaiting_approval', counted: 'kit.counted.awaiting_approval' },
    pending: { shape: 'provisional', icon: ChipHourglass, label: 'kit.statuses.pending', counted: 'kit.counted.pending' },
    awaiting_payment: { shape: 'provisional', icon: ChipCard, label: 'kit.statuses.awaiting_payment', counted: 'kit.counted.awaiting_payment' },
    cancelled: { shape: 'void', icon: ChipCross, label: 'kit.statuses.cancelled', counted: 'kit.counted.cancelled' },
    rejected: { shape: 'void', icon: ChipCross, label: 'kit.statuses.rejected', counted: 'kit.counted.rejected' },
    no_show: { shape: 'void', icon: ChipAlert, label: 'kit.statuses.no_show', counted: 'kit.counted.no_show' },
    completed: { shape: 'past', icon: ChipTick, label: 'kit.statuses.completed', counted: 'kit.counted.completed' },
    expired: { shape: 'past', icon: ChipTimer, label: 'kit.statuses.expired', counted: 'kit.counted.expired' },
    // A catalog row's two states: offered, or resting and not offered, which has no mark. A
    // service and a member of the team are nouns of two genders in Greek, so the word is the
    // caller's.
    active: { shape: 'live', icon: ChipTick },
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
 * The chip: the status's icon, then its label in .admin-chip__label, the span a strike lands on, so
 * the icon is never struck. A count, where given, makes the compact chip Home's awaiting feed draws,
 * in the status's shape. A booking status's chip reads its whole line of the kit's counted group,
 * chosen by the count, since Greek bends the word by the number: the figure, spelled by
 * formatCount in the shell's locale, stands where the line's :count does, in its own span, and the
 * words in theirs. A status the chip does not know keeps the figure before its raw value, and a
 * catalog row's state (active, inactive) given a count does as that status does, the figure before
 * its label.
 */
export function StatusChip({ status, count, label: given, className }: { status: string; count?: number; label?: string; className?: string }) {
    const shell = useShell();
    const known = knownOf(status);
    const label = given ?? statusLabel(status, shell);
    const Icon = known?.icon;

    return (
        <span className={cn('admin-chip', known !== undefined && `admin-chip--${known.shape}`, count !== undefined && 'admin-chip--compact', className)} data-status={status}>
            {Icon !== undefined && (
                <span className="admin-chip__icon">
                    <Icon />
                </span>
            )}
            {count === undefined ? (
                <span className="admin-chip__label">{label}</span>
            ) : (
                counted(count, known !== undefined && 'counted' in known ? shell.choice(known.counted, count, { count: ':count' }) : `:count ${label}`, shell.locale)
            )}
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
