import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { todayIn } from '../../booking/time';
import type { Acts } from '@/bookings/acting';
import type { DialogState } from '@/bookings/BookingDialog';
import type { Detail } from '@/bookings/model';
import { useOpenedDetail } from '@/bookings/opened-detail';
import type { BookingsWords } from '@/bookings/words';
import { owning } from '@/lib/visits';
import { idsOf, move, type Opening, rangeOf, type View, type Who, type Window } from './model';

/*
 * The Calendar's state: the view and the date as state that never reaches the URL, so a reload
 * opens on Day and the tenant's today, Month included, and a day opened from Month as one change
 * of both; a link's opening view and date, read by the server once, seed the two on the first
 * render alone and are remembered nowhere: the address drops the query once they are read, so a
 * reload after a linked open lands on Day and today too; the window as the server's last answer
 * for the range and Who the screen wants, `who` or the picked `staff` list; and the dialog as
 * client state over it, held by useOpenedDetail as a customer's page holds its own.
 *
 * The screen states what it wants and re-reads whatever the props carry that differs: a move of
 * the week, a change of Who, a block opened, and an action's redirect, which lands the page's
 * default props back, are all one rule. Whatever is stale in one render is read in one visit
 * naming only those props, never one visit each: the framework runs one synchronous visit at a
 * time and a second leaving in the same commit interrupts the first with no failure and no retry,
 * which would leave the grid drawing the default week against the range the screen wants. Nothing
 * is optimistic, and every visit owns its failure. Every read preserves the URL, because the
 * calendar's URL is the calendar's and nothing of the view, the date or the open booking survives
 * a reload.
 */

type Input = {
    /** The page's own path, which every read of it names. */
    path: string;
    /** The view and the date a link names, the first render's alone; absent or null, Day and today. */
    linked?: Opening;
    timezone: string;
    weekStart: number;
    /** Whom the screen shows, as it sends it. */
    who: Who;
    /** The ids picked, read where who is picked alone. */
    staff: readonly number[];
    window: Window;
    detail: Detail | null;
    /** The bookings section's words, which an action's failure is worded in. */
    words: BookingsWords;
};

export type Calendar = {
    view: View;
    date: string;
    today: string;
    setView: (view: View) => void;
    /** Day view on the date given, the view and the date changed together so one read follows. */
    showDay: (date: string) => void;
    goToday: () => void;
    previous: () => void;
    next: () => void;
    loading: boolean;
    error: boolean;
    /** The dialog over the calendar, or null while none is open. */
    dialog: DialogState | null;
    open: (uuid: string) => void;
    close: () => void;
    acts: Acts;
};

export function useCalendar({ path, linked, timezone, weekStart, who, staff, window, detail, words }: Input): Calendar {
    const today = todayIn(timezone, Date.now());
    const [view, setView] = useState<View>(() => linked?.view ?? 'day');
    const [date, setDate] = useState(() => linked?.date ?? today);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const opening = useOpenedDetail(detail, words);
    const opened = opening.opened;
    const picked = who === 'picked' ? idsOf(staff).join(',') : '';
    const wanted = { ...rangeOf(view, date, weekStart), who, picked };
    const answered = window.who === 'picked' ? idsOf(window.staff).join(',') : '';
    const windowStale = wanted.from !== window.from || wanted.to !== window.to || wanted.who !== window.who || wanted.picked !== answered;
    const detailStale = opening.stale;
    const linkRead = useRef(false);

    // A linked open's query leaves the address once read, the page's props kept, so nothing of it
    // survives a reload. Declared before the read below, so it runs first in the same commit.
    useEffect(() => {
        if (linkRead.current) {
            return;
        }

        linkRead.current = true;

        if ((linked?.view ?? null) !== null || (linked?.date ?? null) !== null) {
            router.replace({ url: path, preserveState: true, preserveScroll: true });
        }
    });

    useEffect(() => {
        if (!windowStale && !detailStale) {
            return;
        }

        const owned = owning(() => {
            if (windowStale) {
                setError(true);
            }

            if (detailStale) {
                opening.failed();
            }
        });

        router.get(
            path,
            {
                ...(windowStale ? { from: wanted.from, to: wanted.to, ...(wanted.who === 'picked' ? { staff: wanted.picked } : { who: wanted.who }) } : {}),
                ...(detailStale && opened !== null ? { booking: opened } : {}),
            },
            {
                only: [...(windowStale ? ['window'] : []), ...(detailStale ? ['detail'] : [])],
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                replace: true,
                onStart: () => {
                    if (windowStale) {
                        setLoading(true);
                    }

                    owned.onStart();
                },
                onSuccess: () => {
                    if (windowStale) {
                        setError(false);
                    }
                },
                onFinish: () => {
                    setLoading(false);
                    owned.onFinish();
                },
            },
        );
    }, [windowStale, detailStale, wanted.from, wanted.to, wanted.who, wanted.picked, opened]);

    return {
        view,
        date,
        today,
        setView,
        showDay: (day: string) => {
            setView('day');
            setDate(day);
        },
        goToday: () => setDate(today),
        previous: () => setDate(move(view, date, -1)),
        next: () => setDate(move(view, date, 1)),
        loading,
        error,
        dialog: opening.dialog,
        open: opening.open,
        close: opening.close,
        acts: opening.acts,
    };
}
