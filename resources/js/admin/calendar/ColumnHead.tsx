import { DropdownMenu, type MenuItem } from '@/components/ui/dropdown-menu';
import type { TenantClock } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Initials } from '@/components/initials';
import { type DayColumn, dayTitleOf, headHoursOf, type Member, OTHER_BOOKINGS } from './model';
import { type CalendarKey, type CalendarWords, useCalendarWords } from './words';

/*
 * A Day column's head: the member's initials ringed in their hue, their name and the day's hours in
 * mono, "10:00-19:00" or "Not working this day", centred; the head itself is the button opening the
 * kit's dropdown menu under it, titled "<name>, <weekday> <date>", with the items the screen hands,
 * each present by the viewer's permission; a viewer pinned to Me, handed no items, sees the same
 * head as no button. The trailing column of the other bookings is headed by its name alone, and a
 * member's column whose day is not yet read by the initials and the name, no hours line, its button
 * disabled until the day is read: its items would act on the hours of the window read before, Edit
 * this day's hours opening on a day that window does not hold. Every word is a key of the calendar
 * section, each reading on any date shown, the menu's title one whole line.
 */

/** What the menu of a member's column may offer: the screen leaves out an item its viewer may not take. */
export type ColumnActions = {
    book?: (member: Member) => void;
    close?: (member: Member) => void;
    edit?: (member: Member) => void;
    /** The staff page's address of a member, where the viewer may open it. */
    staffPage?: (member: Member) => string;
};

export const NEW_BOOKING_HERE: CalendarKey = 'day.book';
export const CLOSURE_HERE: CalendarKey = 'day.close';
export const EDIT_THIS_DAYS_HOURS: CalendarKey = 'day.edit';
export const OPEN_STAFF_PAGE: CalendarKey = 'day.staff_page';

/** The menu's items for a member, in order, each only where its action is handed. */
function columnItems(member: Member, actions: ColumnActions, words: CalendarWords): MenuItem[] {
    const { book, close, edit, staffPage } = actions;

    return [
        ...(book === undefined ? [] : [{ key: 'book', label: words.text(NEW_BOOKING_HERE), className: 'admin-calendar-column__book', onSelect: () => book(member) }]),
        ...(close === undefined ? [] : [{ key: 'close', label: words.text(CLOSURE_HERE), className: 'admin-calendar-column__close', onSelect: () => close(member) }]),
        ...(edit === undefined ? [] : [{ key: 'edit', label: words.text(EDIT_THIS_DAYS_HOURS), className: 'admin-calendar-column__edit', onSelect: () => edit(member) }]),
        ...(staffPage === undefined ? [] : [{ key: 'staff', label: words.text(OPEN_STAFF_PAGE), className: 'admin-calendar-column__staff', href: staffPage(member) }]),
    ];
}

/** The head's face: the initials, the name and the day's hours, centred, one height on every column. */
const FACE = 'admin-calendar-head flex h-full w-full flex-col items-center justify-center gap-1 px-2 text-center';

export function ColumnHead({ column, date, clock, hue, actions }: { column: DayColumn; date: string; clock: TenantClock; hue: string; actions: ColumnActions }) {
    const words = useCalendarWords();
    const { member, hours } = column;

    if (member === null) {
        return (
            <div className={cn(FACE, 'admin-calendar-head--other')}>
                <span className="admin-calendar-head__name truncate text-sm font-semibold">{words.text(OTHER_BOOKINGS)}</span>
            </div>
        );
    }

    const title = words.text('day.menu', { staff: member.name, date: dayTitleOf(date, clock, words) });
    const items = columnItems(member, actions, words);
    const face = (
        <>
            <Initials name={member.name} hue={hue} size="head" className="admin-calendar-head__initials" />
            <span className="admin-calendar-head__name max-w-full truncate text-sm font-semibold">{member.name}</span>
            {hours !== null && <span className="admin-calendar-head__hours max-w-full truncate font-mono text-xs text-muted-foreground">{headHoursOf(hours, clock, words)}</span>}
        </>
    );

    if (items.length === 0) {
        return <div className={FACE}>{face}</div>;
    }

    if (hours === null) {
        return (
            <button type="button" disabled className={cn(FACE, 'admin-calendar-head__menu admin-calendar-head__menu--unread cursor-default')}>
                {face}
            </button>
        );
    }

    return (
        <DropdownMenu
            label={title}
            title={title}
            items={items}
            align="center"
            className="w-60 min-w-60"
            trigger={
                <button type="button" className={cn(FACE, 'admin-calendar-head__menu cursor-pointer outline-none hover:bg-muted focus-visible:inset-ring-3 focus-visible:inset-ring-ring/50 aria-expanded:bg-muted')}>
                    {face}
                </button>
            }
        />
    );
}
