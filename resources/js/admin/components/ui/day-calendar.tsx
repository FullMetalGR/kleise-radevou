import { useState } from 'react';
import { DayButton, DayPicker, type DayButtonProps, type DayProps } from 'react-day-picker';
import { parseDay } from '../../../booking/time';
import { dayKey, isoWeekday } from '@/lib/day';
import { dayTitle, monthName, monthTitle, weekdayName, yearName } from '@/lib/names';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';

/*
 * The day calendar of the date field and the manual booking wizard, one control the date field
 * renders in its popover and the wizard renders inline, over react-day-picker.
 *
 * Weeks start on the tenant's week start, an ISO weekday, 1 for Monday to 7 for Sunday, mapped to
 * the library's 0 for Sunday to 6, because the calendar opens beside a calendar keyed on the same
 * setting and two week definitions on one screen put the same date in two different weeks. No day
 * is disabled but past today under an earliest day: an admin backdates by design. Single-select and
 * required: pressing the chosen day again changes nothing, so a day can be moved and never
 * unselected. It knows nothing about the field around it; it takes the selected date, which may be
 * empty or not a date, the week start, the tenant's today to open on when nothing is selected and
 * to mark, and a change handler that receives the machine date, YYYY-MM-DD. The month shown follows
 * a selection moved from outside.
 *
 * The library keeps a day as a Date on that day in the browser's zone, at its first moment: local
 * midnight, or the hour the clock jumps to where a change of clock skips midnight (Santiago's
 * 2026-09-06 starts at 01:00). A machine date crosses in and out through the local date fields
 * alone, never the hour, so no zone shifts a day. Every name is ICU's in the language of the
 * strings the calendar is given, never a date-fns locale and never the library's English: the
 * weekdays over the columns, short with the long one as each header's label, the month's title in
 * the caption and as the grid's label, and each day's full date its button is named by, today
 * marked by aria-current rather than a word. Today is the tenant's alone: the library's own reading
 * of the browser's clock marks nothing, and a today the calendar cannot read marks no day. The two
 * turning controls are named by the kit's words; the console's calendar reads English. Each day's
 * button carries its machine date as data-date, the handle a test or a script reaches a day by.
 *
 * Given an earliest day, a field reaching years back (a customer's birthday), the caption becomes
 * the library's month and year dropdowns, from the earliest day's month to today's, each option
 * ICU's month or year in the language and each dropdown named by the kit, and every day after today
 * is disabled, the one case a day is.
 */

type Props = {
    selected: string;
    weekStart: number;
    today: string;
    onChange: (date: string) => void;
    /**
     * The earliest day the calendar reaches, which turns its caption into month and year dropdowns
     * up to today's month.
     */
    earliest?: string;
    className?: string;
};

type Weekday = 0 | 1 | 2 | 3 | 4 | 5 | 6;

/** The library's week start, 0 for Sunday to 6, for each ISO week start, 1 for Monday to 7. */
const WEEK_STARTS: Readonly<Record<number, Weekday>> = { 1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6, 7: 0 };

/** The custom modifier naming the tenant's today, in place of the library's own. */
const TENANT_TODAY = 'tenantToday';

/**
 * A machine date as the library's day, a Date at that day's first moment in the browser's zone;
 * null for a value not a date.
 */
function localOf(value: string): Date | null {
    try {
        const { year, month, day } = parseDay(value);

        return new Date(year, month - 1, day);
    } catch {
        return null;
    }
}

/** The machine date a library day holds in its local fields. */
function keyOf(date: Date): string {
    return dayKey(date.getFullYear(), date.getMonth() + 1, date.getDate());
}

/** The first of a day's month, the month the calendar shows. */
function monthOf(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

/** The library's week start from an ISO week start; Monday for one unread. */
function weekStartsOn(weekStart: number): Weekday {
    return WEEK_STARTS[weekStart] ?? 1;
}

/** A day's cell as the library draws it, flagged today by the tenant's today alone. */
function Cell({ day, modifiers, ...rest }: DayProps) {
    return <td {...rest} data-today={modifiers[TENANT_TODAY] === true ? true : undefined} />;
}

/** A day's button as the library draws it, with its machine date and the tenant's today. */
function Face({ day, modifiers, ...rest }: DayButtonProps) {
    return <DayButton day={day} modifiers={modifiers} data-date={day.isoDate} aria-current={modifiers[TENANT_TODAY] === true ? 'date' : undefined} {...rest} />;
}

export function DayCalendar({ selected, weekStart, today, onChange, earliest, className }: Props) {
    const shell = useShell();
    const language = shell.language;
    const todayDate = localOf(today);
    const chosen = localOf(selected);
    // A month must open: with no selection and no today read, the browser's, marking nothing.
    const [month, setMonth] = useState(monthOf(chosen ?? todayDate ?? new Date()));
    const [followed, setFollowed] = useState(selected);

    if (selected !== followed) {
        setFollowed(selected);

        if (chosen !== null) {
            setMonth(monthOf(chosen));
        }
    }

    const title = (date: Date) => monthTitle(date.getFullYear(), date.getMonth() + 1, language);
    const first = earliest === undefined ? null : localOf(earliest);
    const reach = first === null ? {} : { captionLayout: 'dropdown' as const, startMonth: monthOf(first), endMonth: monthOf(todayDate ?? new Date()) };
    // A day past the tenant's today is no birthday: the library's own matcher disables it.
    const closed = first === null || todayDate === null ? {} : { disabled: { after: todayDate } };

    return (
        <DayPicker
            mode="single"
            required
            selected={chosen ?? undefined}
            onSelect={(date: Date) => {
                const next = keyOf(date);

                if (next !== selected) {
                    onChange(next);
                }
            }}
            month={month}
            onMonthChange={setMonth}
            {...reach}
            {...closed}
            today={todayDate ?? undefined}
            modifiers={{ [TENANT_TODAY]: todayDate ?? false }}
            modifiersClassNames={{ [TENANT_TODAY]: 'admin-day-calendar__today' }}
            weekStartsOn={weekStartsOn(weekStart)}
            lang={shell.locale}
            className={cn('admin-day-calendar', className)}
            classNames={{ today: '' }}
            components={{ Day: Cell, DayButton: Face }}
            formatters={{
                formatCaption: title,
                formatWeekdayName: (date: Date) => weekdayName(isoWeekday(keyOf(date)), 'short', language),
                formatMonthDropdown: (date: Date) => monthName(date.getMonth() + 1, language, undefined, 'long'),
                formatYearDropdown: (date: Date) => yearName(date.getFullYear(), language),
            }}
            labels={{
                labelGrid: title,
                labelWeekday: (date: Date) => weekdayName(isoWeekday(keyOf(date)), 'long', language),
                labelDayButton: (date: Date) => dayTitle(date.getFullYear(), date.getMonth() + 1, date.getDate(), language),
                labelPrevious: () => shell.text('kit.month_grid.previous'),
                labelNext: () => shell.text('kit.month_grid.next'),
                labelMonthDropdown: () => shell.text('kit.month_grid.month'),
                labelYearDropdown: () => shell.text('kit.month_grid.year'),
            }}
        />
    );
}
