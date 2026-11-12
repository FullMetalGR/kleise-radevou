import { useMemo, useRef } from 'react';
import { deleteKeptAlive, type Api } from './api';
import type { Booking } from './booking';
import { holdIsLive } from './countdown';
import { HOLDS_PATH } from './hold';
import { tokenPath } from './manage';

/*
 * The release, over DELETE /holds/{uuid}, which gives the slots back at once rather than when
 * the sweeper next passes.
 *
 * The token travels as the query parameter, which is where this API reads it on a DELETE: the
 * method carries no body. It is written into this one URL and nowhere else, and the URL is
 * handed to the fetch and kept by nothing here.
 *
 * Best effort and fire-and-forget. The server's sweeper is the real guarantee and the client is
 * never trusted to release, so the answer is never read, whatever it is, and a failure is
 * swallowed where it happens and never rendered: a customer who pressed "Pick another time" has
 * already moved on, and a customer whose page is going away is not there to be told.
 *
 * The request is kept alive, because the automatic release fires as the page hides and may be
 * the last thing the document does; a beacon would outlive it too, but cannot carry a DELETE.
 */

/**
 * The release's URL, both halves escaped the way the manage read's are.
 */
export function releasePath(uuid: string, token: string): string {
    return tokenPath(HOLDS_PATH, uuid, token);
}

export function sendRelease(api: Api, uuid: string, token: string): void {
    try {
        deleteKeptAlive(api, releasePath(uuid, token)).catch(() => undefined);
    } catch {
        // A fetch that refused synchronously is a release that did not go, which is allowed.
    }
}

/**
 * The one release a hold gets, by whichever of the flow's two paths comes first: "Pick another
 * time" or the page hiding. It goes only while the hold is live by its own deadline on the
 * browser clock, since past it the sweeper owns the hold; and only once, since a hold this page
 * released is released.
 *
 * A hold handed off to a checkout is marked, not released, and the mark binds the page hiding
 * alone (5.4): the navigation taking the customer to pay is itself a page hide, and the page it
 * lands on is about to settle that hold. "Pick another time" splits on the deadline and never on
 * which control was pressed, so a customer who comes back from the checkout and restarts gives
 * a still-live hold back, rather than leave their own orphaned hold blocking the slot they are
 * about to re-pick.
 *
 * Both marks are the hold's identifier and nothing else, so the next hold is one this page has
 * neither released nor handed off. Neither is the token: a tab that comes back after a hide
 * released its hold keeps its token, so the confirm it still offers is sent and the server
 * answers for the hold.
 */
export interface HoldRelease {
    /** "Pick another time"'s: releases the hold unless there is no credential, it is not live, or it was released. */
    readonly release: (held: Held | null, credential: string | null) => void;
    /** The page hiding's: the same, and nothing for a hold handed off. */
    readonly releaseOnHide: (held: Held | null, credential: string | null) => void;
    /** Marks the hold handed off without releasing it: the payment branch's. */
    readonly handOff: (held: Held) => void;
}

/**
 * What a release reads of a hold: its identifier and its deadline, and nothing that could carry
 * the manage token.
 */
type Held = Pick<Booking, 'uuid' | 'holdExpiresAt'>;

export function useHoldRelease(api: Api, now: () => number): HoldRelease {
    const released = useRef<string | null>(null);
    const handedOff = useRef<string | null>(null);

    return useMemo(() => {
        const release = (held: Held | null, credential: string | null): void => {
            if (held === null || credential === null || released.current === held.uuid || !holdIsLive(held.holdExpiresAt, now())) {
                return;
            }

            released.current = held.uuid;
            sendRelease(api, held.uuid, credential);
        };

        return {
            release,
            releaseOnHide: (held, credential) => {
                if (held?.uuid !== handedOff.current) {
                    release(held, credential);
                }
            },
            handOff: (held) => {
                handedOff.current = held.uuid;
            },
        };
    }, [api, now]);
}
