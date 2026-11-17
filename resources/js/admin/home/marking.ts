import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useShell } from '@/lib/strings';
import { NOT_DONE, owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';

/*
 * To mark's two writes and its selection. A row's Completed and No-show post to the booking
 * dialog's own two routes; a bulk mark posts the outcome and the uuids to the bulk route. Each is
 * a form post the server answers with a redirect back and a flash, so Home's props arrive re-read
 * and a marked row leaves because the server no longer lists it, nothing assumed here. One mark
 * at a time, as the dialog's: while one is in flight every other press is refused. A visit that
 * fails without a flash, a refused body or a lost connection, is said in an error toast, the
 * envelope's sentence where it carries one.
 */

/** A row's own mark, by the path the dialog's button posts to. */
export type RowAction = 'complete' | 'no-show';

/** A bulk mark's outcome, as the bulk route takes it. */
export type Outcome = 'completed' | 'no_show';

/** The mark in flight: a row's, or the bulk's, which names no row. */
export type InFlight = { uuid: string | null; action: RowAction };

export type Marking = {
    busy: InFlight | null;
    markOne: (uuid: string, action: RowAction) => void;
    markMany: (uuids: string[], outcome: Outcome, done: () => void) => void;
};

const BOOKINGS = '/admin/bookings';

/** The bulk route: a selection of bookings marked at once. */
const BULK = `${BOOKINGS}/mark`;

/** The dialog's path for a bulk outcome, so the busy state reads the same either way. */
const ACTION_OF: Record<Outcome, RowAction> = { completed: 'complete', no_show: 'no-show' };

export function useMarking(): Marking {
    const shell = useShell();
    const toasts = useToasts();
    const [busy, setBusy] = useState<InFlight | null>(null);

    const post = (url: string, body: Record<string, string | string[]>, flight: InFlight, done: () => void) => {
        if (busy !== null) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(url, body, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                setBusy(flight);
                owned.onStart();
            },
            onFinish: () => {
                setBusy(null);
                owned.onFinish();
                done();
            },
        });
    };

    return {
        busy,
        markOne: (uuid, action) => post(`${BOOKINGS}/${encodeURIComponent(uuid)}/${action}`, {}, { uuid, action }, () => undefined),
        markMany: (uuids, outcome, done) => post(BULK, { outcome, bookings: uuids }, { uuid: null, action: ACTION_OF[outcome] }, done),
    };
}

export type Selection = {
    /** The selected rows still listed, in the list's order. */
    selected: string[];
    has: (uuid: string) => boolean;
    /** Selects the rows given, or clears them. */
    set: (uuids: string[], on: boolean) => void;
};

/**
 * The rows chosen, read against the rows listed on every render: a row the server no longer
 * lists drops out of the selection, and the rest stay chosen.
 */
export function useSelection(listed: string[]): Selection {
    const [chosen, setChosen] = useState<ReadonlySet<string>>(() => new Set());
    const selected = listed.filter((uuid) => chosen.has(uuid));

    const set = (uuids: string[], on: boolean) =>
        setChosen((current) => {
            const next = new Set(current);

            uuids.forEach((uuid) => (on ? next.add(uuid) : next.delete(uuid)));

            return next;
        });

    return { selected, has: (uuid) => chosen.has(uuid), set };
}
