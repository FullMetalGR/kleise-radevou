import { useRef } from 'react';

/*
 * The synchronous latch and the ticket, together, for the two manage mutations that a customer
 * can walk away from while they fly: the cancel and the move.
 *
 * The latch is set before the request and read at the top of the next press, because the
 * rendered disabled attribute reaches the DOM one macrotask late and is the visible affordance
 * only. The ticket is captured when a request starts and weighed when its verdict lands: a
 * verdict whose ticket is still current frees the latch and may render; any other is stale,
 * renders nothing, announces nothing and frees no latch the next journey owns.
 *
 * retire() is the way out: it frees the latch and bumps the ticket, so a request still in
 * flight lands stale. Starting a request resets nothing on the way in, so without it one hung
 * request would deaden its control for good.
 */

export interface TicketLatch {
    /**
     * Takes the latch, or answers null when a request already holds it. The function returned
     * says, when the verdict lands, whether it is still current, and frees the latch if so.
     */
    readonly begin: () => (() => boolean) | null;
    readonly retire: () => void;
}

export function useTicketLatch(): TicketLatch {
    const flying = useRef(false);
    const ticket = useRef(0);

    return {
        begin: () => {
            if (flying.current) {
                return null;
            }

            flying.current = true;

            const mine = ticket.current;

            return () => {
                if (mine !== ticket.current) {
                    return false;
                }

                flying.current = false;

                return true;
            };
        },
        retire: () => {
            ticket.current += 1;
            flying.current = false;
        },
    };
}
