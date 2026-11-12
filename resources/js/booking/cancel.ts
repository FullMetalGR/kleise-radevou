import { useMutation, useQueryClient } from '@tanstack/react-query';
import { classify, postJson, type Api, type Notice } from './api';
import { useTicketLatch } from './latch';
import { BOOKINGS_PATH, invalidateShown, TOKEN } from './manage';

/*
 * The cancel, over POST /bookings/{uuid}/cancel: the customer gives an appointment up from
 * their magic link.
 *
 * The credential travels in the JSON body and nowhere else (5.5). A POST is the one method
 * this API reads the token from a body on, and the path names the booking alone, so no URL
 * this code builds carries the token into a log, a referrer or a performance entry. From My
 * bookings' list there is no token (6.6): the body is empty, and the credential is the session
 * cookie the browser sends under the list mode's credentials.
 *
 * Nothing is rendered optimistically. The answer is not read at all: what appears next is the
 * booking the invalidated query refetches, so the cancelled sentence on screen is the server's
 * answer about the row rather than this app's assumption about it. The invalidation lives here
 * in the hook rather than in the handler, so one policy sits in one place, and it runs before
 * the ticket is weighed: whether the customer is still watching has no bearing on whether the
 * cache now holds a booking the server has moved.
 *
 * The mutation never retries. A blind second cancel could act on a booking somebody else
 * rescued in between (3.2).
 *
 * The latch is set before the request and read at the top of send, because the rendered
 * disabled attribute reaches the DOM one macrotask late and is the visible affordance only. It
 * is freed by a verdict whose ticket is still current, and by release(), which is what "Keep
 * this booking" calls: starting a cancel resets nothing on the way in, so without that a single
 * hung cancel would deaden the button for good.
 *
 * release() also retires the ticket, which is what makes a verdict that lands afterwards stale.
 * A stale verdict renders nothing and announces nothing: a refusal must not paint "too late to
 * cancel" over a cancel the customer has already withdrawn. It detaches the mutation's state as
 * well, as the move's close does, so a withdrawn cancel is no longer busy: its progress line
 * goes, and the action area it held is the customer's again. The hook-level invalidation is the
 * mutation's own and still runs.
 */

export function cancelPath(uuid: string): string {
    return `${BOOKINGS_PATH}/${encodeURIComponent(uuid)}/cancel`;
}

export async function postCancel(api: Api, uuid: string, token: string | null): Promise<void> {
    await postJson(api, cancelPath(uuid), token === null ? {} : { [TOKEN]: token });
}

/**
 * The two ways a cancel lands, as the manage view sees them: the transition the server made,
 * which is a signal and not a payload, or a refusal already classified into a sentence and a
 * politeness.
 */
export interface CancelOutcome {
    readonly onCancelled: () => void;
    readonly onRefused: (notice: Notice) => void;
}

export interface Cancel {
    /** True while a cancel is on the wire: the visible affordance, one macrotask late. */
    readonly busy: boolean;
    /** The token where one is in hand, or null from the list, whose cookie is the credential. */
    readonly send: (uuid: string, token: string | null) => void;
    /** Frees the latch and retires the ticket, so a verdict still in flight lands as stale. */
    readonly release: () => void;
}

interface CancelVariables {
    readonly uuid: string;
    readonly token: string | null;
}

export function useCancel(api: Api, outcome: CancelOutcome): Cancel {
    const latch = useTicketLatch();
    const client = useQueryClient();
    const mutation = useMutation({
        mutationFn: (variables: CancelVariables) => postCancel(api, variables.uuid, variables.token),
        onSuccess: (_answer, variables) => {
            invalidateShown(client, variables.uuid);
        },
    });

    function send(uuid: string, token: string | null): void {
        const current = latch.begin();

        if (current === null) {
            return;
        }

        mutation.mutate(
            { uuid, token },
            {
                onSuccess: () => {
                    if (current()) {
                        outcome.onCancelled();
                    }
                },
                onError: (error) => {
                    if (current()) {
                        outcome.onRefused(classify(error));
                    }
                },
            },
        );
    }

    return {
        busy: mutation.isPending,
        send,
        release: () => {
            latch.retire();
            mutation.reset();
        },
    };
}
