import { type ComponentProps, type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import type { TenantClock } from '@/lib/format';
import { cn } from '@/lib/utils';
import { BookingChip } from './Chip';
import type { Chip, Colouring } from './model';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * A cell's chips with a cap: the first ones up to the cap, in the order handed, and where more
 * remain, a line saying how many more in place of the rest. That line opens a popover over the
 * cell, titled by the caller, which lists every chip of the cell in the same order; a chip there
 * opens its booking as one in the cell does, the popover closing first. Escape or a press outside
 * closes it, and focus goes back to the line.
 *
 * While the popover is up a clear layer lies over the whole window, over the page and under the
 * popover, so a press outside the popover lands on the layer and on nothing below it, no day's
 * menu, no chip and no drag: its click closes the popover and gives the line focus. The layer
 * locks nothing, so the page does not move as the popover opens and scrolls under it as ever,
 * and a touch that scrolls the page, being no click, leaves the popover up.
 *
 * On a chip's press the popover is gone before the line takes focus and the booking opens, so
 * the dialog the booking opens in hands focus back to the line, a control still on the page, and
 * never to the chip that is gone with the popover.
 *
 * A cell may draw asides among its chips, each at a time of its own: one goes before the chips
 * shown from its time on, counts for no cap and is not listed in the popover. A chip shown in the
 * cell carries what the caller hands it, a drag; one in the popover carries nothing.
 */

export const MORE: CalendarKey = 'cells.more';

type Props = {
    chips: readonly Chip[];
    /** The most chips the cell shows before the line. */
    cap: number;
    /** The popover's title: the cell's day, and its row where it has one. */
    title: string;
    colouring: Colouring;
    clock: TenantClock;
    onOpen: (uuid: string) => void;
    /** What a chip shown in the cell carries beside its own. */
    carried?: (chip: Chip) => Carried | undefined;
    asides?: readonly Aside[];
    className?: string;
};

type Carried = Omit<ComponentProps<'button'>, 'children' | 'onClick' | 'type'>;

/** Something a cell draws among its chips: start is the wall clock it goes at, as a chip's is. */
export type Aside = { key: string; start: string; node: ReactNode };

type Entry = { chip: Chip } | Aside;

const startOf = (entry: Entry) => ('chip' in entry ? entry.chip.start : entry.start);

/** The chips shown with the asides among them by start, an aside before a chip at its own time; the chips keep their order. */
function placed(shown: readonly Chip[], asides: readonly Aside[]): Entry[] {
    return [...asides, ...shown.map((chip) => ({ chip }))].sort((a, b) => (startOf(a) < startOf(b) ? -1 : startOf(a) > startOf(b) ? 1 : 0));
}

/** The clear layer's place: the whole window, over the page's levels and under the popover's. */
const SCRIM = 'admin-calendar-scrim fixed inset-0 z-40';

/**
 * The clear layer, there while `up` holds. It is an element of the document's own and not one the
 * cell draws, so the pointer on it is on no cell, and it is the same element from the popover's
 * opening to its closing, so a press that goes down on it comes up on it.
 */
function useScrim(up: boolean, onPress: () => void) {
    useEffect(() => {
        if (!up) {
            return;
        }

        const scrim = document.createElement('div');

        scrim.className = SCRIM;
        scrim.addEventListener('click', onPress);
        document.body.append(scrim);

        return () => scrim.remove();
    }, [up, onPress]);
}

export function CappedChips({ chips, cap, title, colouring, clock, onOpen, carried, asides = [], className }: Props) {
    const words = useCalendarWords();
    const [open, setOpen] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const shown = chips.slice(0, cap);
    const hidden = chips.length - shown.length;

    const shut = useCallback(() => {
        flushSync(() => setOpen(false));
        more.current?.focus();
    }, []);

    useScrim(open && hidden > 0, shut);

    const openListed = (uuid: string) => {
        shut();
        onOpen(uuid);
    };

    return (
        <ul className={cn('admin-calendar-chips flex min-w-0 flex-col gap-0.5', className)}>
            {placed(shown, asides).map((entry) =>
                'chip' in entry ? (
                    <li key={entry.chip.uuid} className="min-w-0">
                        <BookingChip {...carried?.(entry.chip)} chip={entry.chip} colouring={colouring} clock={clock} onOpen={onOpen} />
                    </li>
                ) : (
                    <li key={entry.key} className="min-w-0">
                        {entry.node}
                    </li>
                ),
            )}
            {hidden > 0 && (
                <li className="min-w-0">
                    <Popover open={open} onOpenChange={setOpen}>
                        <PopoverTrigger asChild>
                            <button ref={more} type="button" className="admin-calendar-more w-full cursor-pointer rounded-sm px-1 text-left text-xs leading-4 font-medium text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50">
                                {words.choice(MORE, hidden)}
                            </button>
                        </PopoverTrigger>
                        <PopoverContent aria-label={title} className="admin-calendar-popover flex w-64 flex-col gap-1 p-2">
                            <p className="admin-calendar-popover__title px-1 pb-1 text-xs font-semibold text-muted-foreground">{title}</p>
                            <ul className="flex flex-col gap-0.5">
                                {chips.map((chip) => (
                                    <li key={chip.uuid} className="min-w-0">
                                        <BookingChip chip={chip} colouring={colouring} clock={clock} onOpen={openListed} />
                                    </li>
                                ))}
                            </ul>
                        </PopoverContent>
                    </Popover>
                </li>
            )}
        </ul>
    );
}
