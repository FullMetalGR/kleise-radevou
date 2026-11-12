import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { BookingDialog } from '@/bookings/BookingDialog';
import { type Detail, staffShown } from '@/bookings/model';
import { useOpenedDetail } from '@/bookings/opened-detail';
import { BookingsProvider, bookingsWordsOf } from '@/bookings/words';
import { Strip } from '@/figures/kit';
import { Activity, Awaiting, type HomeActivityRow, type HomeBooking, NextUp } from '@/home/feeds';
import { RangeCard, type RangeFigures, Tiles, TopTables } from '@/home/figures';
import { type Setup, SetupCard } from '@/home/SetupCard';
import { type HomeWords, homeWordsOf } from '@/home/words';
import { isoWeekday } from '@/lib/day';
import { clockOf, formatCount } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { useShell } from '@/lib/strings';
import { usePartialRead } from '@/lib/partial-read';
import { owning } from '@/lib/visits';
import { CALENDAR_PATH } from '@/pages/Calendar';
import type { BookingsStrings, HomeStrings, SharedProps } from '@/types';
import { useNewBooking } from '@/wizard/NewBooking';

/*
 * Home, the landing of every viewer holding viewBookings. The title reads Home; then, in order,
 * the setup checklist while the setup prop carries a fact still false, its booking item opening
 * the wizard with no prefill; the range card, a choice of range being a visit to Home with the
 * range in the address (none for this week) that keeps the scroll and reads range_figures alone,
 * the card busy while it reads and a failed read said in Home's own sentence; the six tiles and
 * the footnote; Next up beside Awaiting approval; Top services beside Top staff; Recent activity
 * beside the next seven days, one bar per day from the tenant's today, the confirmed part in the
 * theme colour and the awaiting share in amber, each a link opening the Calendar's Day on its
 * date. Every figure is the server's and every word is a key of the home section.
 *
 * Between the tiles and the days, the three feeds: Next up, Awaiting approval and Recent activity.
 * A row naming a booking opens the booking dialog on Home as the customer's page opens it: the
 * page's own state, the detail prop read for its uuid by a partial reload with the URL preserved,
 * so a reload lands with no dialog; the dialog's actions post as every screen's do (useActs), and
 * the server's redirect back lands Home with every prop re-read, the feeds included, the page then
 * reading the detail again for the booking still open.
 */

/**
 * A day of the next seven, BookingRecords::perDay: the confirmed and the awaiting approval
 * starting on it.
 */
export type HomeDay = { date: string; confirmed: number; awaiting: number };

type Props = SharedProps & {
    strings: SharedProps['strings'] & { home?: HomeStrings; bookings?: BookingsStrings };
    range_figures: RangeFigures;
    days: HomeDay[];
    next_up: HomeBooking[];
    awaiting: HomeBooking[];
    activity: HomeActivityRow[];
    /** How many staff rows the tenant has, the rows half of the one-value rule. */
    members: number;
    detail: Detail | null;
    setup?: Setup;
};

/** What a choice of range reads again: the range's figures alone. */
const RANGE_PROPS = ['range_figures'] as const;

/** Home's own address, where the dialog's detail is read. */
const PATH = '/admin';

/** The Calendar's Day on a date, read once by the Calendar on its first render. */
export function dayLink(date: string): string {
    return `${CALENDAR_PATH}?view=day&date=${date}`;
}

/** The day's name under its bar: the short weekday and the day of the month, no full date. */
function dayName(date: string, words: HomeWords): string {
    return words.text('days.day', { weekday: weekdayName(isoWeekday(date), 'short', words.language), day: String(Number(date.slice(8, 10))) });
}

/**
 * The next seven days: the sum in the header strip, one whole sentence per case, the awaiting
 * share named only where there is one: one awaiting a choice by the week's bookings, several
 * awaiting one plain sentence, since the week then holds two bookings or more; a bar per day, its
 * height the day's bookings over the busiest day's, the awaiting share stacked in amber, the
 * attention chip's amber, on the confirmed part in the theme colour, a day with none a line at
 * nought; the count above, the day's name beneath, the whole a link opening the Calendar's Day on
 * its date; and the footnote saying so.
 */
function Days({ days, words }: { days: HomeDay[]; words: HomeWords }) {
    const total = (day: HomeDay) => day.confirmed + day.awaiting;
    const most = Math.max(1, ...days.map(total));
    const bookings = days.reduce((sum, day) => sum + total(day), 0);
    const awaiting = days.reduce((sum, day) => sum + day.awaiting, 0);
    const count = formatCount(bookings, words.locale);
    const summary =
        awaiting === 0
            ? words.choice('days.bookings', bookings, { count })
            : awaiting === 1
              ? words.choice('days.summary.one_awaiting', bookings, { count, awaiting: formatCount(awaiting, words.locale) })
              : words.text('days.summary.many_awaiting', { count, awaiting: formatCount(awaiting, words.locale) });

    return (
        <section className="admin-home-days rounded-xl border" aria-labelledby="home-days-title">
            <Strip titleId="home-days-title" title={words.choice('days.title', days.length)} aside={<span className="admin-home-days__summary text-sm text-muted-foreground">{summary}</span>} />
            <ol className="grid h-[180px] grid-cols-7 gap-2 p-4">
                {days.map((day) => {
                    const named = dayName(day.date, words);
                    const count = total(day);

                    return (
                        <li key={day.date} className="admin-home-day h-full" data-date={day.date} data-confirmed={day.confirmed} data-awaiting={day.awaiting}>
                            <Link href={dayLink(day.date)} className="admin-home-day__link flex h-full flex-col items-center justify-end gap-1 rounded-md text-center hover:bg-muted" aria-label={words.choice('days.link', count, { day: named, count: formatCount(count, words.locale) })}>
                                <span className="flex min-h-0 w-full flex-1 flex-col items-center justify-end gap-1" aria-hidden="true">
                                    <span className="admin-home-day__count font-mono text-xs">{formatCount(count, words.locale)}</span>
                                    {count === 0 ? (
                                        <span className="admin-home-day__none block h-0.5 w-full bg-border" />
                                    ) : (
                                        <span className="admin-home-day__bar flex max-h-[calc(100%-1.25rem)] w-full flex-col overflow-hidden rounded-t-md" style={{ height: `${Math.round((count / most) * 100)}%` }}>
                                            {day.awaiting > 0 && <span className="admin-home-day__awaiting basis-0 bg-amber-400" style={{ flexGrow: day.awaiting }} />}
                                            {day.confirmed > 0 && <span className="admin-home-day__confirmed basis-0 bg-primary" style={{ flexGrow: day.confirmed }} />}
                                        </span>
                                    )}
                                </span>
                                <span className="admin-home-day__name text-xs text-muted-foreground" aria-hidden="true">
                                    {named}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ol>
            <p className="admin-home-days__footnote px-4 pb-3 text-xs text-muted-foreground">{words.text('days.footnote')}</p>
        </section>
    );
}

export default function Home() {
    const { props } = usePage<Props>();
    const clock = clockOf(props);
    const language = useShell().language;
    const words = homeWordsOf(props.strings, language);
    const bookingsWords = bookingsWordsOf(props.strings, language);
    const { open: openWizard } = useNewBooking();
    const shown = staffShown(props.entitlements, props.members, null);
    const opening = useOpenedDetail(props.detail, bookingsWords);
    const { opened, stale, failed, acts } = opening;
    const pending = props.nav.find((entry) => entry.url === '/admin/bookings')?.pending ?? 0;
    const ranging = usePartialRead(RANGE_PROPS);

    useEffect(() => {
        if (opened === null || !stale) {
            return;
        }

        router.get(PATH, { booking: opened }, { only: ['detail'], preserveState: true, preserveScroll: true, preserveUrl: true, replace: true, ...owning(failed) });
    }, [stale, opened]);

    return (
        <BookingsProvider words={bookingsWords}>
            <Head title={words.text('page_title')} />
            <div className="admin-home-screen flex flex-col gap-4">
                <h1 className="text-2xl font-semibold">{words.text('title')}</h1>
                <SetupCard setup={props.setup} entitlements={props.entitlements} bookable={Boolean(props.catalog)} onBook={() => openWizard()} words={words} />
                <RangeCard range={props.range_figures.range} clock={clock} words={words} busy={ranging.loading} failed={ranging.error} onChoose={(word) => ranging.read(PATH, word === 'week' ? {} : { range: word })} />
                <Tiles figures={props.range_figures} words={words} />
                <div className="grid gap-4 lg:grid-cols-2">
                    <NextUp rows={props.next_up} clock={clock} words={words} staffShown={shown} onOpen={opening.open} />
                    <Awaiting rows={props.awaiting} clock={clock} words={words} staffShown={shown} onOpen={opening.open} pending={pending} />
                </div>
                <TopTables figures={props.range_figures} words={words} />
                <div className="grid gap-4 lg:grid-cols-2">
                    <Activity rows={props.activity} clock={clock} words={words} staffShown={shown} onOpen={opening.open} />
                    <Days days={props.days} words={words} />
                </div>
                {opening.dialog !== null && (
                    <BookingDialog
                        state={opening.dialog}
                        staffShown={shown}
                        clock={clock}
                        weekStart={props.settings.week_start}
                        onClose={opening.close}
                        failure={acts.failure}
                        busy={acts.busy}
                        onAct={props.detail?.state === 'found' && props.detail.booking.uuid === opened ? acts.act(props.detail.booking.uuid) : undefined}
                    />
                )}
            </div>
        </BookingsProvider>
    );
}
