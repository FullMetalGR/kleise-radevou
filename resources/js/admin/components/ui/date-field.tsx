import { useState, type ComponentProps } from 'react';
import { todayIn } from '../../../booking/time';
import { formatDate, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { DayCalendar } from './day-calendar';
import { Popover, PopoverContent, PopoverTrigger } from './popover';

/*
 * The date field. The value in and out is a machine date, YYYY-MM-DD, and the empty string is a
 * legal value meaning no date: the Bookings filters' From and To start empty and empty means
 * unbounded, so the empty value has to be reachable again, which is what Clear is for.
 *
 * The trigger spells the value in the tenant's date format, never the browser locale, and carries
 * the raw machine value as its DOM value. A value it cannot parse renders verbatim, and the field
 * never rewrites a value it did not produce: nothing here calls onChange except a person picking
 * a day or pressing Clear.
 *
 * The popover holds the day calendar over the date library, weeks starting on the tenant's week
 * start, single-select and required, with Clear beside it. The calendar takes focus on its one
 * tab stop as it opens, the chosen day or else today; Escape, a pick or Clear closes it and focus
 * goes back to the trigger. Escape reaches the popover alone as the top layer, so inside a modal
 * one press peels the popover and leaves the modal open. The popover's name and Clear are the
 * kit's words, from the strings the field is given.
 */

type Props = Omit<ComponentProps<'button'>, 'value' | 'onChange' | 'children' | 'type'> & {
    value: string;
    onChange: (value: string) => void;
    clock: TenantClock;
    weekStart: number;
    today?: string;
    /**
     * The earliest day the calendar reaches by its month and year dropdowns, for a date years back.
     */
    earliest?: string;
};

export function DateField({ value, onChange, clock, weekStart, today, earliest, className, disabled, ...rest }: Props) {
    const shell = useShell();
    const [open, setOpen] = useState(false);

    const answer = (next: string) => {
        setOpen(false);

        if (next !== value) {
            onChange(next);
        }
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    value={value}
                    disabled={disabled}
                    className={cn(
                        'admin-date-field__trigger admin-input admin-input--figures h-8 w-full rounded-md border bg-background px-3 py-1 text-left font-mono text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-60',
                        className,
                    )}
                    {...rest}
                >
                    {value === '' ? '' : formatDate(value, clock)}
                </button>
            </PopoverTrigger>
            <PopoverContent
                aria-label={shell.text('kit.date_field.choose')}
                className="admin-date-field__popover"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();

                    if (event.currentTarget instanceof HTMLElement) {
                        event.currentTarget.querySelector<HTMLElement>('button[data-date][tabindex="0"]')?.focus();
                    }
                }}
            >
                <DayCalendar className="p-2" selected={value} weekStart={weekStart} today={today ?? todayIn(clock.timezone, Date.now())} earliest={earliest} onChange={answer} />
                <div className="flex justify-end border-t px-2 py-1.5">
                    <button type="button" className="admin-date-field__clear rounded-md px-2 py-1 text-sm hover:bg-accent" onClick={() => answer('')}>
                        {shell.text('kit.date_field.clear')}
                    </button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
