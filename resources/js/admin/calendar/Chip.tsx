import type { ComponentProps, ReactNode } from 'react';
import { NoteSheet } from '@/components/glyphs';
import { statusLabel } from '@/components/ui/status-chip';
import { formatTimeOfDay, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { InsideMark } from './Badge';
import { type Badge, blockHueOf, type Chip, type Colouring, fillOf } from './model';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * A booking chip: one line naming a booking in a day's cell or a list, its start time in the
 * tenant's format and the customer, the name cut short where the line runs out. It wears its hue
 * as a block does, the fill mixed toward the surface and a bar of the hue down its start edge,
 * Colour by deciding the hue. The marks follow the name and none of them is a word: a request is
 * outlined in its badge's amber, an approved booking awaiting payment in its badge's dashed sky, a
 * note the customer wrote is a folded sheet and a closure holding the booking's time the
 * exclamation mark a narrow block wears, each of those two named for a screen reader and on
 * hover. The chip's own name says the time, the customer and the status in words, then the marks'
 * names.
 *
 * It knows nothing of where it sits: a press hands its booking to the caller, and whatever else a
 * caller hands a button (a drag's handlers, a class) passes through to it.
 */

export const CHIP_NAME: CalendarKey = 'cells.chip';

export const ERASED_CUSTOMER: CalendarKey = 'cells.erased';

export const NOTED: CalendarKey = 'badges.noted';

export const INSIDE: CalendarKey = 'badges.closure';

/** The outline a badged booking's chip wears, its badge's own colours; the legend's swatch for it wears the same. */
export const CHIP_OUTLINES: Record<Badge, string> = {
    awaiting_approval: 'outline-2 -outline-offset-1 outline-solid outline-amber-500',
    awaiting_payment: 'outline-2 -outline-offset-1 outline-dashed outline-sky-600',
};

type Props = Omit<ComponentProps<'button'>, 'children' | 'onClick' | 'type'> & {
    chip: Chip;
    colouring: Colouring;
    clock: TenantClock;
    onOpen: (uuid: string) => void;
};

/** A mark after the name: no word on the chip, its name read out and shown on hover. */
function Mark({ name, className, children }: { name: string; className: string; children: ReactNode }) {
    return (
        <span role="img" aria-label={name} title={name} className={cn('inline-flex shrink-0 items-center', className)}>
            {children}
        </span>
    );
}

export function BookingChip({ chip, colouring, clock, onOpen, className, style, ...rest }: Props) {
    const shell = useShell();
    const words = useCalendarWords();
    const hue = blockHueOf(chip, colouring);
    const time = formatTimeOfDay(chip.start.slice(11, 16), clock);
    const customer = chip.customer ?? words.text(ERASED_CUSTOMER);

    return (
        <button
            type="button"
            {...rest}
            className={cn(
                'admin-calendar-chip flex w-full min-w-0 cursor-pointer items-center gap-1 rounded-sm border-l-3 px-1 py-px text-left text-xs leading-4 text-foreground outline-none hover:brightness-95 focus-visible:ring-3 focus-visible:ring-ring/50',
                chip.badge === null ? null : ['admin-calendar-chip--outlined', CHIP_OUTLINES[chip.badge]],
                className,
            )}
            style={{ backgroundColor: fillOf(hue), borderLeftColor: hue, ...style }}
            data-uuid={chip.uuid}
            data-status={chip.status}
            data-badge={chip.badge ?? undefined}
            onClick={() => onOpen(chip.uuid)}
        >
            <span className="sr-only">{words.text(CHIP_NAME, { time, customer, status: statusLabel(chip.status, shell) })}</span>
            <span className="admin-calendar-chip__time shrink-0 font-medium tabular-nums" aria-hidden="true">
                {time}
            </span>
            <span className={cn('admin-calendar-chip__customer min-w-0 flex-1 truncate', chip.customer === null && 'italic')} aria-hidden="true">
                {customer}
            </span>
            {chip.noted && (
                <Mark name={words.text(NOTED)} className="admin-calendar-chip__noted text-muted-foreground">
                    <NoteSheet />
                </Mark>
            )}
            {chip.inside && (
                <Mark name={words.text(INSIDE)} className="admin-calendar-chip__inside">
                    <InsideMark />
                </Mark>
            )}
        </button>
    );
}
