import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type RefObject } from 'react';
import { ApiError, classify, postJson, type Api, type Notice } from './api';
import { AVAILABILITY_KEY, fetchAvailabilityAt } from './availability';
import { useTicketLatch } from './latch';
import { BOOKINGS_PATH, holdsCredential, invalidateShown, TOKEN } from './manage';
import type { LineKey } from './words';

/*
 * The move: the customer takes their appointment to another start from the magic link, over the
 * two routes that exist for it.
 *
 * The read is GET /bookings/{uuid}/availability, never the public GET /availability: the public
 * read counts the booking's own items as busy and so can never offer the small nudge the move
 * would take. It sends no items, because the server plans the chain from the booking's rows
 * exactly as the move plans it, and the dialog could not send wrong ones. The token travels as
 * the token query parameter, the GET transport, and nowhere else. From My bookings' list there is
 * no token: the read and the write name none, and the credential is the session cookie the
 * browser sends under the list mode's credentials.
 *
 * Its key is ['availability', 'move', uuid, from, to] (3.1): under the availability prefix, so
 * the one invalidation of 3.3 reaches it on a reschedule 409 without a second rule, and with no
 * token in it, because the uuid already names the one booking a manage view holds and a key
 * carrying a secret would put that secret into every devtools frame that reads the cache. Fresh
 * for no time at all, like the public read, and subscribed only while the dialog is open,
 * because the dialog is what mounts it.
 *
 * The write is POST /bookings/{uuid}/reschedule with the token and the start in the body and
 * nothing else: the services, their order, the staff and the prices are read from the rows, so
 * a body cannot restate them. It never retries (3.2) and its answer is not read: nothing is
 * rendered optimistically (invariant 12), and the new time arrives through the invalidated
 * booking query, from the server. The two invalidations live in the hook and not in a handler
 * (3.3), and they run whether or not the customer is still watching: a success invalidates the
 * booking, a 409 invalidates the availability prefix and so refetches the still-subscribed move
 * list once.
 */

/**
 * The second element of the move availability key, beside the prefix every availability key
 * shares.
 */
const MOVE_KEY = 'move';

/*
 * The dialog's heading, the control that opens it, its close, its progress line and what a landed
 * move says, each by its key of booking.php.
 */
export const PICK_SENTENCE: LineKey = 'move.pick';

export const RESCHEDULE_LABEL: LineKey = 'move.reschedule';

export const CLOSE_LABEL: LineKey = 'move.close';

export const MOVING_SENTENCE: LineKey = 'move.moving';

export const MOVED_SENTENCE: LineKey = 'move.moved';

function moveAvailabilityKey(uuid: string | null, from: string, to: string) {
    return [AVAILABILITY_KEY, MOVE_KEY, uuid, from, to] as const;
}

/**
 * The read's URL: the booking's path with the token, where there is one, and the window as its
 * query, and nothing else in it. Every part is escaped, so the identifier cannot become two path
 * segments and the token cannot become a second parameter.
 */
export function moveAvailabilityUrl(uuid: string, token: string | null, from: string, to: string): string {
    const params = new URLSearchParams(token === null ? { from, to } : { [TOKEN]: token, from, to });

    return `${BOOKINGS_PATH}/${encodeURIComponent(uuid)}/availability?${params.toString()}`;
}

/**
 * Enabled with the booking named and a credential to send: the token, or the list mode's cookie.
 */
export function moveAvailabilityQuery(api: Api, uuid: string | null, token: string | null, from: string, to: string) {
    return queryOptions({
        queryKey: moveAvailabilityKey(uuid, from, to),
        queryFn: () => {
            if (uuid === null || !holdsCredential(api, token)) {
                throw new Error('The move availability query ran without the booking and a credential.');
            }

            return fetchAvailabilityAt(api, moveAvailabilityUrl(uuid, token, from, to));
        },
        enabled: uuid !== null && holdsCredential(api, token),
        staleTime: 0,
    });
}

export function reschedulePath(uuid: string): string {
    return `${BOOKINGS_PATH}/${encodeURIComponent(uuid)}/reschedule`;
}

async function postReschedule(api: Api, uuid: string, token: string | null, start: string): Promise<void> {
    await postJson(api, reschedulePath(uuid), token === null ? { start } : { [TOKEN]: token, start });
}

interface MoveVariables {
    readonly uuid: string;
    readonly token: string | null;
    readonly start: string;
}

/**
 * What the manage view holds of the dialog: whether it is open, what the last move tried from
 * it was refused with, and whether one is on the wire; and the three things the customer can do
 * there.
 */
export interface Reschedule {
    readonly open: boolean;
    readonly refusal: Notice | null;
    /** True while a move from the open dialog is on the wire: the visible affordance, one macrotask late. */
    readonly moving: boolean;
    readonly begin: () => void;
    readonly close: () => void;
    readonly move: (start: string) => void;
}

/**
 * The dialog's state and the move, owned by the manage view so that they outlive the dialog.
 *
 * The open state is here, and so is the one place the dialog closes: close() and a current
 * success. close() retires the ticket and frees the latch, so a verdict still in flight lands
 * stale, renders nothing and announces nothing, and it detaches the mutation's state, so a dialog
 * opened again is not shown the progress line of a request this page has stopped waiting on. The
 * hook-level invalidations are the mutation's own and run regardless.
 *
 * The credentials are the view's: the identifier from its state, the token from its one ref,
 * read when the press happens and copied nowhere; in My bookings' list the ref holds no token and
 * the cookie the browser sends is the credential.
 */
export function useReschedule(api: Api, uuid: string | null, token: RefObject<string | null>, onMoved: () => void): Reschedule {
    const [open, setOpen] = useState(false);
    const [refusal, setRefusal] = useState<Notice | null>(null);
    const latch = useTicketLatch();
    const client = useQueryClient();
    const mutation = useMutation({
        mutationFn: (variables: MoveVariables) => postReschedule(api, variables.uuid, variables.token, variables.start),
        retry: false,
        onSuccess: (_answer, variables) => {
            invalidateShown(client, variables.uuid);
        },
        onError: (error) => {
            if (error instanceof ApiError && error.status === 409) {
                void client.invalidateQueries({ queryKey: [AVAILABILITY_KEY] });
            }
        },
    });

    function move(start: string): void {
        const secret = token.current;

        if (uuid === null || !holdsCredential(api, secret)) {
            return;
        }

        const current = latch.begin();

        if (current === null) {
            return;
        }

        mutation.mutate(
            { uuid, token: secret, start },
            {
                onSuccess: () => {
                    if (current()) {
                        setOpen(false);
                        setRefusal(null);
                        onMoved();
                    }
                },
                onError: (error) => {
                    if (current()) {
                        setRefusal(classify(error));
                    }
                },
            },
        );
    }

    return {
        open,
        refusal,
        moving: mutation.isPending,
        begin: () => {
            setRefusal(null);
            setOpen(true);
        },
        close: () => {
            latch.retire();
            mutation.reset();
            setRefusal(null);
            setOpen(false);
        },
        move,
    };
}
