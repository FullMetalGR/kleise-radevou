import { createContext, type ReactNode, useContext } from 'react';
import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { ShiftWords } from '@/shifts/words';
import type { CalendarStrings, ShiftsStrings } from '@/types';
import type { Language } from '../../booking/language';

/*
 * The Calendar's words: the `calendar` section of lang/{locale}/admin, which CalendarController
 * names beside the shell's, read by key in the page's language. The screen builds them from its
 * props and provides them to the chrome, the grid and the dialogs under it; a render outside any
 * provider reads the keys themselves.
 *
 * The provider carries the shifts section beside it, which the screen holds for the day editor: a
 * closure drawn on a Day column says what the Shifts week says of the same closure, in that
 * section's sentence, so each is spelled once.
 */

export type CalendarKey = KeysOf<CalendarStrings>;

export type CalendarWords = Strings<CalendarKey>;

/** The calendar section a page's strings prop carries, in its language; a missing section answers the keys. */
export function calendarWordsOf(strings: { calendar?: CalendarStrings } | undefined, language: Language): CalendarWords {
    return stringsOf<CalendarStrings>(strings?.calendar ?? null, language);
}

/** The shifts section a page's strings prop carries, in its language; a missing section answers the keys. */
export function shiftWordsOf(strings: { shifts?: ShiftsStrings } | undefined, language: Language): ShiftWords {
    return stringsOf<ShiftsStrings>(strings?.shifts ?? null, language);
}

type Words = { calendar: CalendarWords; shifts: ShiftWords };

const CalendarContext = createContext<Words>({
    calendar: stringsOf<CalendarStrings>(null, 'en'),
    shifts: stringsOf<ShiftsStrings>(null, 'en'),
});

/** The calendar's words and the shifts section's for everything under the screen. */
export function CalendarProvider({ calendar, shifts, children }: Words & { children: ReactNode }) {
    return <CalendarContext.Provider value={{ calendar, shifts }}>{children}</CalendarContext.Provider>;
}

/** The calendar's words: the screen's, or the keys themselves outside a provider. */
export function useCalendarWords(): CalendarWords {
    return useContext(CalendarContext).calendar;
}

/** The shifts section's words the screen carries, which a Day column's closure is worded in. */
export function useClosureWords(): ShiftWords {
    return useContext(CalendarContext).shifts;
}
