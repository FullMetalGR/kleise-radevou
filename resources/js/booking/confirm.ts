import { useMutation } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { ApiError, classify, postJson, type Api, type Notice } from './api';
import { parseBooking, type Booking } from './booking';

/*
 * The confirm, over POST /bookings/{uuid}/confirm: the customer's last press on the free and
 * pay-on-site path.
 *
 * The credential travels in the JSON body and nowhere else (5.5). A POST is the one method
 * this API reads the token from a body on, and the path names the booking alone, so no URL
 * this code builds carries the token into a log, a referrer or a performance entry.
 *
 * The answer is the guest view of the booking the transition left, read by the same parse the
 * hold's answer is, so the done step branches on the status the server returned and on nothing
 * else (2.6).
 *
 * A refusal is a sentence and a politeness, classified by 4.1 and by nothing this module
 * decides, with one exception: a 410 is the server saying the hold ran out, which forces the
 * expiry flag on top of the ordinary refusal so the button stays disabled even where the
 * browser's clock disagrees (4.2). Every other status is rendered and not interpreted.
 *
 * The mutation never retries: that is the query client's default for every mutation, because a
 * blind second confirm could act twice (3.2). It invalidates nothing: a confirm 409 is about
 * the booking's own state and says nothing a refetched slot list would fix (3.3).
 *
 * The latch is set before the request and read at the top of send, and it is not freed when
 * the answer lands. That is deliberate: a refused confirm is re-read before anything is shown,
 * the re-read is the flow's, and the latch must stay held across it so no second confirm
 * starts while a booking that may already be settled is being looked up. The flow frees it
 * through release() at the end of its recovery, without touching this hook.
 *
 * The latch is the review button's, whichever branch the button is on. The hand-off to a
 * checkout sends no confirm but takes the same latch through latch() and keeps it on the way
 * out, so a second press navigates nothing; while it is held that way the button reads busy,
 * because no mutation is out to say so. Only reset() or release() frees it.
 */

const CONFIRM_TOKEN = 'token';

/**
 * The path of one booking's confirm. The identifier is escaped: it reaches this app from a
 * server answer, and a path segment assembled from a value is a path segment that must not be
 * able to become two.
 */
export function confirmPath(uuid: string): string {
    return `/api/v1/bookings/${encodeURIComponent(uuid)}/confirm`;
}

export async function postConfirm(api: Api, uuid: string, token: string): Promise<Booking> {
    const path = confirmPath(uuid);
    const booking = parseBooking(await postJson(api, path, { [CONFIRM_TOKEN]: token }));

    if (booking === null) {
        throw new Error(`POST ${path} answered something other than a booking.`);
    }

    return booking;
}

/**
 * The status of a hold the server says is gone. The one status the app reads rather than
 * renders.
 */
const HOLD_EXPIRED_STATUS = 410;

/**
 * What a refused confirm hands the flow: the sentence to render, and whether the server said
 * the hold itself is gone.
 */
export interface ConfirmRefusal {
    readonly notice: Notice;
    readonly expired: boolean;
}

export function refusalOf(error: unknown): ConfirmRefusal {
    return { notice: classify(error), expired: error instanceof ApiError && error.status === HOLD_EXPIRED_STATUS };
}

/**
 * The two ways a confirm lands, as the flow sees them: the booking the server settled, or a
 * refusal already classified.
 */
export interface ConfirmOutcome {
    readonly onConfirmed: (booking: Booking) => void;
    readonly onRefused: (refusal: ConfirmRefusal) => void;
}

export interface Confirm {
    /** True while a confirm is on the wire: the visible affordance, one macrotask late. */
    readonly busy: boolean;
    readonly send: (uuid: string, token: string) => void;
    /** Frees the latch. The flow's to call once it has finished with a refusal. */
    readonly release: () => void;
    /** Frees the latch and detaches any confirm still out. The restart control's. */
    readonly reset: () => void;
    /**
     * Takes the latch for a press that sends no confirm, the hand-off's, answering whether it
     * was free. It stays taken until reset() or release().
     */
    readonly latch: () => boolean;
}

interface ConfirmVariables {
    readonly uuid: string;
    readonly token: string;
}

export function useConfirm(api: Api, outcome: ConfirmOutcome): Confirm {
    const flying = useRef(false);
    const [latched, setLatched] = useState(false);
    const mutation = useMutation({ mutationFn: (variables: ConfirmVariables) => postConfirm(api, variables.uuid, variables.token) });

    function send(uuid: string, token: string): void {
        if (flying.current) {
            return;
        }

        flying.current = true;

        mutation.mutate(
            { uuid, token },
            {
                onSuccess: outcome.onConfirmed,
                onError: (error) => outcome.onRefused(refusalOf(error)),
            },
        );
    }

    function latch(): boolean {
        if (flying.current) {
            return false;
        }

        flying.current = true;
        setLatched(true);

        return true;
    }

    return {
        busy: mutation.isPending || latched,
        send,
        release: () => {
            flying.current = false;
            setLatched(false);
        },
        reset: () => {
            flying.current = false;
            setLatched(false);
            mutation.reset();
        },
        latch,
    };
}
