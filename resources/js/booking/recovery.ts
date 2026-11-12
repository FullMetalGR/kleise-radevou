import { useEffect, useMemo, useRef } from 'react';
import type { Api } from './api';
import type { Booking } from './booking';
import { fetchBooking } from './manage';

/*
 * The confirm refusal recovery: why no refusal is a dead end.
 *
 * Every refused confirm, whatever its status, re-reads the booking with the credentials in hand
 * before anything is shown, because a refusal is not always the truth about the booking: the
 * first confirm may have committed and lost its answer, the service may have sent it for
 * approval, or the page-hide release may have run while the customer was in another app. The
 * re-read is GET /bookings/{uuid} through the client directly and never through the query cache:
 * it is one answer to one press, not a subscription, and nothing else on the page reads it. It is
 * sent once and never retried, and a failed one is swallowed: the original refusal stands.
 *
 * Each recovery takes a ticket. Its answer lands only while that ticket is still the current one;
 * "Pick another time" and an unmount abandon it, so a re-read resolving after the customer walked
 * away lands nowhere: it renders nothing, announces nothing, moves no focus and frees no latch,
 * since both may belong to a later journey by then.
 *
 * A current answer is one of two outcomes, and which one is decided here: the booking, when the
 * re-read found it settled, or the refusal standing, for anything else, a failure included. What
 * each outcome does to the page is the flow's.
 */

/**
 * The statuses a refused confirm's journey counts as having succeeded in (4.4 step 7): the
 * booking is the customer's, confirmed or waiting on someone else, and a refusal screen would be
 * a lie.
 */
export const SETTLED_STATUSES: readonly string[] = ['confirmed', 'awaiting_approval', 'awaiting_payment'];

/**
 * The re-read itself: the booking when it reads settled, and null when it reads anything else or
 * cannot be read at all.
 */
export async function reread(api: Api, uuid: string, token: string): Promise<Booking | null> {
    try {
        const booking = await fetchBooking(api, uuid, token);

        return SETTLED_STATUSES.includes(booking.status) ? booking : null;
    } catch {
        return null;
    }
}

/**
 * The two ends of a current recovery.
 */
export interface Recovered {
    /** The re-read found the booking settled: the journey succeeded. */
    readonly settled: (booking: Booking) => void;
    /** Anything else: the original refusal stands. */
    readonly stands: () => void;
}

export interface Recovery {
    /**
     * Re-reads the booking and ends in one of the two outcomes, only if no later recovery or
     * abandon came first. Without both credentials there is nothing to read, and the refusal
     * stands.
     */
    readonly recover: (held: Pick<Booking, 'uuid'> | null, credential: string | null, end: Recovered) => void;
    /** Makes any recovery still out a stale one: the restart control's. */
    readonly abandon: () => void;
}

export function useRecovery(api: Api): Recovery {
    const ticket = useRef(0);

    // A flow that unmounts abandons whatever it had out.
    useEffect(
        () => () => {
            ticket.current += 1;
        },
        [],
    );

    return useMemo(
        () => ({
            recover: (held, credential, end) => {
                ticket.current += 1;

                const mine = ticket.current;
                const answer = held === null || credential === null ? Promise.resolve(null) : reread(api, held.uuid, credential);

                void answer.then((settled) => {
                    if (ticket.current !== mine) {
                        return;
                    }

                    if (settled === null) {
                        end.stands();
                    } else {
                        end.settled(settled);
                    }
                });
            },
            abandon: () => {
                ticket.current += 1;
            },
        }),
        [api],
    );
}
