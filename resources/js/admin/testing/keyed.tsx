import type { ReactNode } from 'react';
import type { Language } from '../../booking/language';
import { ADMIN_LINES_EN } from '../lines.generated';
import { type KeysOf, ShellProvider, type ShellWords, type Strings, stringsOf } from '@/lib/strings';
import { BookingsProvider, type BookingsWords } from '@/bookings/words';
import { CalendarProvider, type CalendarWords } from '@/calendar/words';
import type { CustomersWords, WizardWords } from '@/customers/words';
import type { HomeWords } from '@/home/words';
import type { ReportsWords } from '@/reports/words';
import type { ServicesWords } from '@/services/words';
import type { BillingWords, EmailsWords, PaymentsWords, SettingsWords } from '@/settings/words';
import type { ShiftWords } from '@/shifts/words';
import { StaffProvider, type StaffWords } from '@/staff/words';
import type { BillingStrings, BookingsStrings, CalendarStrings, CustomersStrings, EmailsStrings, HomeStrings, PaymentsStrings, ReportsStrings, ServicesStrings, SettingsStrings, ShellStrings, ShiftsStrings, StaffStrings, TeamStrings, WizardStrings } from '@/types';

/*
 * The keyed tables the admin's component tests render with: a section of lang/en/admin as the
 * generated module carries it, every value replaced by its own key in brackets, the placeholders
 * its English carries kept after the key, so a filled sentence shows what was put into it. A test
 * reads what it expects through the same table, never a sentence, and a key the file gains needs
 * no second spelling here. The tables are built from the English the export writes, php artisan
 * lang:export admin, which the test and the type-check scripts run first.
 */

type Table = { readonly [key: string]: string | Table };

/** A value's placeholders, each :name once, in the order they first appear. */
function placeholdersOf(value: string): string[] {
    return [...new Set([...value.matchAll(/(?<![A-Za-z0-9_:]):([A-Za-z][A-Za-z0-9_]*)/g)].map((match) => `:${match[1] ?? ''}`))];
}

/** A table with every value its key in brackets, read with dots from the table's root, its placeholders kept. */
export function keyedTable<T>(english: T, prefix = ''): T {
    const table = english as unknown as Table;

    return Object.fromEntries(
        Object.entries(table).map(([key, value]) => {
            const path = prefix === '' ? key : `${prefix}.${key}`;

            return [key, typeof value === 'string' ? `[${[path, ...placeholdersOf(value)].join(' ')}]` : keyedTable(value, path)];
        }),
    ) as T;
}

/** The shell's section, keyed. */
export const KEYED_SHELL: ShellStrings = keyedTable<ShellStrings>(ADMIN_LINES_EN.shell);

/** The keyed shell's words, which a test reads its expectations through: shell.text(KEY). */
export function keyedShell(language: Language = 'en'): ShellWords {
    return stringsOf<ShellStrings>(KEYED_SHELL, language);
}

/** Home's section, keyed. */
export const KEYED_HOME: HomeStrings = keyedTable<HomeStrings>(ADMIN_LINES_EN.home);

/** The keyed home section's words, which a test reads its expectations through: home.text(KEY). */
export function keyedHome(language: Language = 'en'): HomeWords {
    return stringsOf<HomeStrings>(KEYED_HOME, language);
}

/** Reports' section, keyed. */
export const KEYED_REPORTS: ReportsStrings = keyedTable<ReportsStrings>(ADMIN_LINES_EN.reports);

/** The keyed reports section's words, which a test reads its expectations through: reports.text(KEY). */
export function keyedReports(language: Language = 'en'): ReportsWords {
    return stringsOf<ReportsStrings>(KEYED_REPORTS, language);
}

/** The Team screen's section, keyed. */
export const KEYED_TEAM: TeamStrings = keyedTable<TeamStrings>(ADMIN_LINES_EN.team);

/** The keyed team section's words, which a test reads its expectations through: team.text(KEY). */
export function keyedTeam(language: Language = 'en'): Strings<KeysOf<TeamStrings>> {
    return stringsOf<TeamStrings>(KEYED_TEAM, language);
}

/** The Calendar's section, keyed. */
export const KEYED_CALENDAR: CalendarStrings = keyedTable<CalendarStrings>(ADMIN_LINES_EN.calendar);

/** The keyed calendar section's words, which a test reads its expectations through: calendar.text(KEY). */
export function keyedCalendar(language: Language = 'en'): CalendarWords {
    return stringsOf<CalendarStrings>(KEYED_CALENDAR, language);
}

/** The bookings section, keyed. */
export const KEYED_BOOKINGS: BookingsStrings = keyedTable<BookingsStrings>(ADMIN_LINES_EN.bookings);

/** The keyed bookings section's words, which a test reads its expectations through: bookings.text(KEY). */
export function keyedBookings(language: Language = 'en'): BookingsWords {
    return stringsOf<BookingsStrings>(KEYED_BOOKINGS, language);
}

/** The customers section, keyed, the wizard's group within it. */
export const KEYED_CUSTOMERS: CustomersStrings = keyedTable<CustomersStrings>(ADMIN_LINES_EN.customers);

/** The keyed customers section's words, which a test reads its expectations through: customers.text(KEY). */
export function keyedCustomers(language: Language = 'en'): CustomersWords {
    return stringsOf<CustomersStrings>(KEYED_CUSTOMERS, language);
}

/** The wizard's group as the shared props carry it, keyed from the section's root, so its keys read wizard.title. */
export const KEYED_WIZARD: WizardStrings = KEYED_CUSTOMERS.wizard;

/** The keyed wizard's words, which a test reads its expectations through: wizard.text(KEY). */
export function keyedWizard(language: Language = 'en'): WizardWords {
    return stringsOf<WizardStrings>(KEYED_WIZARD, language);
}

/** The Services screen's section, keyed. */
export const KEYED_SERVICES: ServicesStrings = keyedTable<ServicesStrings>(ADMIN_LINES_EN.services);

/** The keyed services section's words, which a test reads its expectations through. */
export function keyedServices(language: Language = 'en'): ServicesWords {
    return stringsOf<ServicesStrings>(KEYED_SERVICES, language);
}

/** The Staff screen's section, keyed. */
export const KEYED_STAFF: StaffStrings = keyedTable<StaffStrings>(ADMIN_LINES_EN.staff);

/** The keyed staff section's words, which a test reads its expectations through. */
export function keyedStaff(language: Language = 'en'): StaffWords {
    return stringsOf<StaffStrings>(KEYED_STAFF, language);
}

/** A tree inside the keyed staff section, as the Staff screen provides it. */
export function WithStaff({ language = 'en', children }: { language?: Language; children: ReactNode }) {
    return <StaffProvider words={keyedStaff(language)}>{children}</StaffProvider>;
}

/** The Settings screen's section, keyed. */
export const KEYED_SETTINGS: SettingsStrings = keyedTable<SettingsStrings>(ADMIN_LINES_EN.settings);

/** The keyed settings section's words, which a test reads its expectations through. */
export function keyedSettings(language: Language = 'en'): SettingsWords {
    return stringsOf<SettingsStrings>(KEYED_SETTINGS, language);
}

/** The Payments section of Settings, keyed. */
export const KEYED_PAYMENTS: PaymentsStrings = keyedTable<PaymentsStrings>(ADMIN_LINES_EN.payments);

/** The keyed payments section's words, which a test reads its expectations through. */
export function keyedPayments(language: Language = 'en'): PaymentsWords {
    return stringsOf<PaymentsStrings>(KEYED_PAYMENTS, language);
}

/** The Billing screen and the Settings Danger zone, keyed. */
export const KEYED_BILLING: BillingStrings = keyedTable<BillingStrings>(ADMIN_LINES_EN.billing);

/** The keyed billing section's words, which a test reads its expectations through. */
export function keyedBilling(language: Language = 'en'): BillingWords {
    return stringsOf<BillingStrings>(KEYED_BILLING, language);
}

/** The Settings screen's Emails section, keyed. */
export const KEYED_EMAILS: EmailsStrings = keyedTable<EmailsStrings>(ADMIN_LINES_EN.emails);

/** The keyed emails section's words, which a test reads its expectations through. */
export function keyedEmails(language: Language = 'en'): EmailsWords {
    return stringsOf<EmailsStrings>(KEYED_EMAILS, language);
}

/** The Shifts screen's section, keyed. */
export const KEYED_SHIFTS: ShiftsStrings = keyedTable<ShiftsStrings>(ADMIN_LINES_EN.shifts);

/** The keyed shifts section's words. */
export function keyedShifts(language: Language = 'en'): ShiftWords {
    return stringsOf<ShiftsStrings>(KEYED_SHIFTS, language);
}

/** A tree inside the keyed bookings section, as a screen opening the dialog provides it. */
export function WithBookings({ language = 'en', children }: { language?: Language; children: ReactNode }) {
    return <BookingsProvider words={keyedBookings(language)}>{children}</BookingsProvider>;
}

/** A tree inside the keyed calendar section and the keyed shifts section beside it, as the Calendar provides them. */
export function WithCalendar({ language = 'en', children }: { language?: Language; children: ReactNode }) {
    return (
        <CalendarProvider calendar={keyedCalendar(language)} shifts={keyedShifts(language)}>
            {children}
        </CalendarProvider>
    );
}

/** A tree inside the keyed shell, in the language given, English unless named. */
export function WithShell({ language = 'en', children }: { language?: Language; children: ReactNode }) {
    return (
        <ShellProvider strings={KEYED_SHELL} language={language}>
            {children}
        </ShellProvider>
    );
}
