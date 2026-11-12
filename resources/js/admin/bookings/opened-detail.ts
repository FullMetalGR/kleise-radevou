import { useState } from 'react';
import { type Acts, useActs } from './acting';
import type { DialogState } from './BookingDialog';
import type { Detail } from './model';
import type { BookingsWords } from './words';

/*
 * The booking dialog held as a screen's own state over its detail prop, the shape the two
 * calendars and a customer's page share: a booking is opened by its uuid and the detail prop is
 * read for it by a partial reload with the URL preserved, so a reload lands with no dialog. This
 * is the one home of what they share: the uuid open, whether the detail the props carry answers
 * for another booking or none, which is the stale read the screen makes again, every redirect
 * back landing the page's default props included, the dialog's state from the two, and opening,
 * closing and the read's failure. The read itself stays the screen's, since the calendars read
 * the detail in the same visit as the window and never in one of its own.
 */

export type OpenedDetail = {
    /** The booking open, or null while the dialog is closed. */
    opened: string | null;
    /** Whether a booking is open and the detail prop answers for another, or for none: the screen reads it again. */
    stale: boolean;
    /** The dialog over the screen, or null while none is open. */
    dialog: DialogState | null;
    open: (uuid: string) => void;
    close: () => void;
    /** The screen's read of the detail failed, which the dialog says until it is opened or closed again. */
    failed: () => void;
    acts: Acts;
};

/** The booking a detail answers for, found or missing. */
function uuidOf(detail: Detail | null): string | null {
    return detail === null ? null : detail.state === 'found' ? detail.booking.uuid : detail.uuid;
}

export function useOpenedDetail(detail: Detail | null, words: BookingsWords): OpenedDetail {
    const [opened, setOpened] = useState<string | null>(null);
    const [openFailed, setOpenFailed] = useState(false);
    const acts = useActs(words);
    const answers = detail !== null && uuidOf(detail) === opened;

    const choose = (uuid: string | null) => {
        setOpenFailed(false);
        acts.clear();
        setOpened(uuid);
    };

    return {
        opened,
        stale: opened !== null && !answers,
        dialog: opened === null ? null : openFailed ? { kind: 'error' } : answers && detail !== null ? { kind: 'detail', detail } : { kind: 'loading' },
        open: (uuid) => choose(uuid),
        close: () => choose(null),
        failed: () => setOpenFailed(true),
        acts,
    };
}
