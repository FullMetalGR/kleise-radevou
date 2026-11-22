import type { ComponentProps, ReactNode } from 'react';
import { NoteSheet } from '@/components/glyphs';
import { statusLabel } from '@/components/ui/status-chip';
import { formatTimeOfDay, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { InsideMark } from './Badge';
import { type Badge, blockFillOf, blockHueOf, type Chip, type Colouring, struckOf } from './model';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * A booking chip: one line naming a booking in a day's cell or a list, its start time in the
 * tenant's format and the customer, the name cut short where the line runs out. It wears its hue
 * as a block does, the fill mixed toward the surface and a four-pixel bar of the hue down its start
 * edge, Colour by deciding the hue; a booking cancelled, rejected, missed or expired has the softer fill of
 * the tertiary ink and its customer struck through. The marks follow the name and none of them is
 * a word: a request is outlined solid in the attention colour deepened to an amber that holds as a
 * line on a light ground, an approved
 * booking awaiting payment dashed in the theme colour, as its badge is, a
 * note the customer wrote is a folded sheet and a closure holding the booking's time the
 * exclamation mark a narrow block wears, each of those two named for a screen reader and on
 * hover. The chip's own name says the time, the customer and the status in words, then the marks'
 * names.
 *
 * Cut short, for a cell too narrow for a name, it is the start time alone, set a little tighter
 * and centred on a thinner edge so a 24-hour time fits a day of a phone's week, struck through
 * where the customer would be, its marks read out and not drawn, its outline as ever.
 *
 * It knows nothing of where it sits: a press hands its booking to the caller, and whatever else a
 * caller hands a button (a drag's handlers, a class) passes through to it.
 */

export const CHIP_NAME: CalendarKey = 'cells.chip';

export const ERASED_CUSTOMER: CalendarKey = 'cells.erased';

export const NOTED: CalendarKey = 'badges.noted';

export const INSIDE: CalendarKey = 'badges.closure';

/**
 * The outline a badged booking's chip wears, as its block's; the legend's swatch for it wears the
 * same. A request's is coloured by the stylesheet's rule on its class, the amber its block's
 * outline takes there, deeper than the attention colour, which is too pale a line on a light
 * ground.
 */
export const CHIP_OUTLINES: Record<Badge, string> = {
    awaiting_approval: 'admin-calendar-outline--awaiting_approval outline-2 -outline-offset-1 outline-solid',
    awaiting_payment: 'outline-2 -outline-offset-1 outline-dashed outline-primary',
};

type Props = Omit<ComponentProps<'button'>, 'children' | 'onClick' | 'type'> & {
    chip: Chip;
    colouring: Colouring;
    clock: TenantClock;
    onOpen: (uuid: string) => void;
    /** The start time alone, for a cell too narrow for the customer. */
    short?: boolean;
};

/** A mark after the name: no word on the chip, its name read out and shown on hover. */
function Mark({ name, className, children }: { name: string; className: string; children: ReactNode }) {
    return (
        <span role="img" aria-label={name} title={name} className={cn('inline-flex shrink-0 items-center', className)}>
            {children}
        </span>
    );
}

export function BookingChip({ chip, colouring, clock, onOpen, short = false, className, style, ...rest }: Props) {
    const shell = useShell();
    const words = useCalendarWords();
    const hue = blockHueOf(chip, colouring);
    const time = formatTimeOfDay(chip.start.slice(11, 16), clock);
    const customer = chip.customer ?? words.text(ERASED_CUSTOMER);
    const struck = struckOf(chip.status);

    return (
        <button
            type="button"
            {...rest}
            className={cn(
                'admin-calendar-chip flex w-full min-w-0 cursor-pointer items-center gap-1 rounded-md border-l-4 px-1 py-px text-left text-xs leading-4 text-foreground outline-none hover:brightness-95 focus-visible:ring-3 focus-visible:ring-ring/50',
                chip.badge === null ? null : ['admin-calendar-chip--outlined', CHIP_OUTLINES[chip.badge]],
                struck && 'admin-calendar-chip--struck',
                short && 'admin-calendar-chip--short justify-center overflow-hidden border-l-2 px-0',
                className,
            )}
            style={{ backgroundColor: blockFillOf(hue, chip.status), borderLeftColor: hue, ...style }}
            data-uuid={chip.uuid}
            data-status={chip.status}
            data-badge={chip.badge ?? undefined}
            onClick={() => onOpen(chip.uuid)}
        >
            <span className="sr-only">{words.text(CHIP_NAME, { time, customer, status: statusLabel(chip.status, shell) })}</span>
            <span className={cn('admin-calendar-chip__time shrink-0 font-medium tabular-nums', short && 'tracking-tight', short && struck && 'line-through')} aria-hidden="true">
                {time}
            </span>
            {!short && (
                <span className={cn('admin-calendar-chip__customer min-w-0 flex-1 truncate', chip.customer === null && 'italic', struck && 'line-through')} aria-hidden="true">
                    {customer}
                </span>
            )}
            {chip.noted && (
                <Mark name={words.text(NOTED)} className={cn('admin-calendar-chip__noted text-muted-foreground', short && 'sr-only')}>
                    <NoteSheet />
                </Mark>
            )}
            {chip.inside && (
                <Mark name={words.text(INSIDE)} className={cn('admin-calendar-chip__inside', short && 'sr-only')}>
                    <InsideMark />
                </Mark>
            )}
        </button>
    );
}
