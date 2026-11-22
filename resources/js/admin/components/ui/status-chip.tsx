import type { ComponentType } from 'react';
import { type ShellKey, type ShellWords, useShell } from '@/lib/strings';
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
 * A booking status's label is a key of the shell's section, read in the page's language through
 * the strings the chip is given: the admin's in the user's language, the console's in English. A
 * catalog row's two states keep their shapes here and take their word from the caller, the screen
 * that names the row. A chip carries no figure: a count beside a status is its screen's to spell.
 */

export type ChipShape = 'live' | 'attention' | 'provisional' | 'void' | 'past';

/** A booking status's chip: its shape, its icon and its word in the shell's kit. */
type BookingChip = { readonly shape: ChipShape; readonly icon: ComponentType; readonly label: ShellKey };

/** A catalog row's state: its shape and its icon, if it has one, the word the caller's own screen holds. */
type CatalogChip = { readonly shape: ChipShape; readonly icon?: ComponentType };

const STATUSES: Readonly<Record<string, BookingChip | CatalogChip>> = {
    confirmed: { shape: 'live', icon: ChipTick, label: 'kit.statuses.confirmed' },
    awaiting_approval: { shape: 'attention', icon: ChipClock, label: 'kit.statuses.awaiting_approval' },
    pending: { shape: 'provisional', icon: ChipHourglass, label: 'kit.statuses.pending' },
    awaiting_payment: { shape: 'provisional', icon: ChipCard, label: 'kit.statuses.awaiting_payment' },
    cancelled: { shape: 'void', icon: ChipCross, label: 'kit.statuses.cancelled' },
    rejected: { shape: 'void', icon: ChipCross, label: 'kit.statuses.rejected' },
    no_show: { shape: 'void', icon: ChipAlert, label: 'kit.statuses.no_show' },
    completed: { shape: 'past', icon: ChipTick, label: 'kit.statuses.completed' },
    expired: { shape: 'past', icon: ChipTimer, label: 'kit.statuses.expired' },
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
 * the icon is never struck.
 */
export function StatusChip({ status, label: given, className }: { status: string; label?: string; className?: string }) {
    const shell = useShell();
    const known = knownOf(status);
    const label = given ?? statusLabel(status, shell);
    const Icon = known?.icon;

    return (
        <span className={cn('admin-chip', known !== undefined && `admin-chip--${known.shape}`, className)} data-status={status}>
            {Icon !== undefined && (
                <span className="admin-chip__icon">
                    <Icon />
                </span>
            )}
            <span className="admin-chip__label">{label}</span>
        </span>
    );
}

/**
 * A booking status in words, as its chip labels it, in the strings' language. Any other value is
 * itself: an unrecognised one, and a catalog row's active or inactive, whose word is its screen's.
 */
export function statusLabel(status: string, shell: ShellWords): string {
    const known = knownOf(status);

    return known === undefined || !('label' in known) ? status : shell.text(known.label);
}
