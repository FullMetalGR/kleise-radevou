import { useEffect, useId, useRef, useState, type ComponentProps, type KeyboardEvent } from 'react';
import { formatTimeOfDay, type TenantClock } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Popover, PopoverContent, PopoverTrigger } from './popover';

/*
 * The time field. The value in and out is a machine time, HH:MM or HH:MM:SS, empty meaning none;
 * the trigger spells it in the tenant's time format with no conversion, a time of day carrying no
 * instant, and carries the machine value as its DOM value.
 *
 * The popover is a listbox of every time in the day stepped at the tenant's granularity. A stored
 * value off that grid is displayed and kept, never snapped: it is spliced into the list at its
 * chronological place and marked selected, so the keyboard reaches it and picking it again is an
 * exact no-op. A value moves onto the grid only when a person picks a different time; snapping on
 * render would turn renaming a staff member and pressing save into an edit of a time nobody
 * touched.
 *
 * Not a filtering combobox: typing jumps the highlight and the whole day stays on screen, because
 * a time's neighbours are what the eye needs. Type-ahead searches the display labels first, so on
 * a twelve-hour tenant "9" lands on nine in the morning, then the machine values, so "14" lands on
 * two in the afternoon, and last the machine values without their leading zero, so "9" on a
 * twenty-four-hour tenant lands on 09:00. The listbox takes focus as it opens; Escape or a pick
 * closes it and focus goes back to the trigger. Escape reaches the popover alone as the top
 * layer, so inside a modal one press peels the list and leaves the modal open.
 */

const TIME = /^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/;
const TYPE_AHEAD_MS = 1000;

function secondsOf(value: string): number | null {
    const match = TIME.exec(value);

    return match === null ? null : Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3] ?? 0);
}

/**
 * Every time of the day at the granularity in minutes, HH:MM, with the kept value spliced in at
 * its place when it is a time the grid does not already spell. A kept value naming a grid time
 * with its seconds written out takes that time's place, so the list holds the value exactly as it
 * came.
 */
export function timesOfDay(granularity: number, kept: string): string[] {
    const step = Number.isInteger(granularity) && granularity > 0 ? granularity : 5;
    const times: string[] = [];

    for (let minute = 0; minute < 1440; minute += step) {
        times.push(`${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`);
    }

    const at = secondsOf(kept);

    if (at === null || times.includes(kept)) {
        return times;
    }

    const grid = times.filter((time) => secondsOf(time) !== at);
    const place = grid.findIndex((time) => (secondsOf(time) ?? 0) > at);

    grid.splice(place === -1 ? grid.length : place, 0, kept);

    return grid;
}

type Props = Omit<ComponentProps<'button'>, 'value' | 'onChange' | 'children' | 'type'> & {
    value: string;
    onChange: (value: string) => void;
    clock: TenantClock;
    granularity: number;
};

export function TimeField({ value, onChange, clock, granularity, className, disabled, ...rest }: Props) {
    const listId = useId();
    const typed = useRef({ text: '', at: 0 });
    const [open, setOpen] = useState(false);
    const times = timesOfDay(granularity, value);
    const labels = times.map((time) => formatTimeOfDay(time, clock));
    const [highlight, setHighlight] = useState(0);
    const optionId = (at: number) => `${listId}-${at}`;

    useEffect(() => {
        if (open) {
            const shown = document.getElementById(optionId(highlight));

            shown?.scrollIntoView({ block: 'nearest' });
        }
    });

    const show = () => {
        setHighlight(Math.max(times.indexOf(value), 0));
        typed.current = { text: '', at: 0 };
        setOpen(true);
    };

    const pick = (time: string) => {
        setOpen(false);

        if (time !== value) {
            onChange(time);
        }
    };

    const jump = (character: string, now: number) => {
        const text = (now - typed.current.at < TYPE_AHEAD_MS ? typed.current.text : '') + character.toLowerCase();
        const byLabel = labels.findIndex((label) => label.toLowerCase().startsWith(text));
        const byValue = byLabel !== -1 ? byLabel : times.findIndex((time) => time.startsWith(text));
        const found = byValue !== -1 ? byValue : times.findIndex((time) => time.replace(/^0/, '').startsWith(text));

        typed.current = { text, at: now };

        if (found !== -1) {
            setHighlight(found);
        }
    };

    const keys = (event: KeyboardEvent<HTMLUListElement>) => {
        const last = times.length - 1;
        const moves: Record<string, number> = { ArrowDown: highlight + 1, ArrowUp: highlight - 1, Home: 0, End: last, PageDown: highlight + 12, PageUp: highlight - 12 };
        const move = moves[event.key];
        const time = times[highlight];

        if (move !== undefined) {
            event.preventDefault();
            setHighlight(Math.min(Math.max(move, 0), last));
        } else if (event.key === 'Enter' && time !== undefined) {
            event.preventDefault();
            pick(time);
        } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            jump(event.key, event.timeStamp);
        }
    };

    return (
        <Popover open={open} onOpenChange={(next) => (next ? show() : setOpen(false))}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    value={value}
                    aria-haspopup="listbox"
                    disabled={disabled}
                    className={cn(
                        'admin-time-field__trigger admin-input admin-input--figures h-8 w-full rounded-md border bg-background px-3 py-1 text-left font-mono text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-60',
                        className,
                    )}
                    {...rest}
                >
                    {value === '' ? '' : formatTimeOfDay(value, clock)}
                </button>
            </PopoverTrigger>
            <PopoverContent role="presentation" className="admin-time-field__popover max-h-[min(18rem,var(--radix-popover-content-available-height))]">
                <ul role="listbox" id={listId} tabIndex={0} aria-label={rest['aria-label']} aria-activedescendant={optionId(highlight)} className="admin-time-field__list py-1 outline-none" onKeyDown={keys}>
                    {times.map((time, at) => (
                        <li
                            key={time}
                            id={optionId(at)}
                            role="option"
                            data-value={time}
                            aria-selected={time === value}
                            data-highlighted={at === highlight ? '' : undefined}
                            className="admin-time-field__option cursor-pointer px-3 py-1 font-mono text-sm data-highlighted:bg-accent aria-selected:font-semibold"
                            onClick={() => pick(time)}
                        >
                            {labels[at]}
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
