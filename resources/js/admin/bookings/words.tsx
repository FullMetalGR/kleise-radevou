import { createContext, type ReactNode, useContext } from 'react';
import { type KeysOf, type Strings, stringsOf } from '@/lib/strings';
import type { BookingsStrings } from '@/types';
import type { Language } from '../../booking/language';

/*
 * The words of the Bookings screen and of the booking dialog every screen opens: the `bookings`
 * section of lang/{locale}/admin, which BookingListController, CalendarController,
 * CustomerController and HomeController name beside the shell's, read by key in the page's
 * language. A screen builds the words from its props, reads them itself and provides them to the
 * dialog and the list under it; a render outside any provider reads the keys themselves.
 */

export type BookingsKey = KeysOf<BookingsStrings>;

export type BookingsWords = Strings<BookingsKey>;

/** The bookings section a page's strings prop carries, in its language; a missing section answers the keys. */
export function bookingsWordsOf(strings: { bookings?: BookingsStrings } | undefined, language: Language): BookingsWords {
    return stringsOf<BookingsStrings>(strings?.bookings ?? null, language);
}

const BookingsContext = createContext<BookingsWords>(stringsOf<BookingsStrings>(null, 'en'));

/** The bookings words for the list and the dialog under a screen. */
export function BookingsProvider({ words, children }: { words: BookingsWords; children: ReactNode }) {
    return <BookingsContext.Provider value={words}>{children}</BookingsContext.Provider>;
}

/** The bookings words: the screen's, or the keys themselves outside a provider. */
export function useBookings(): BookingsWords {
    return useContext(BookingsContext);
}
