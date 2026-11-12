import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef } from 'react';
import { ApiError, classify, postJson, type Api, type Notice } from './api';
import { AVAILABILITY_KEY, type AvailabilityItem } from './availability';
import type { Channel } from './attributes';
import { asRecord, parseBooking, type Booking } from './booking';
import type { LineKey } from './words';

/*
 * The hold, over POST /holds. It fires at the details submit and nowhere else, because the
 * endpoint refuses a request without a name and a valid email and the details step is where
 * those first exist.
 *
 * The body is the customer trimmed, with an empty phone omitted rather than sent as an empty
 * string, the chain's items with their staff_id explicit, and the start exactly as the
 * availability answer spelled it: nothing here reconstructs a timestamp, so the value that
 * round-trips is the value the server offered, byte for byte.
 *
 * The answer is the booking of booking.ts, which a confirm answers with as well, and the
 * manage token beside it, once in its life. A hold that is not pending is its own outcome.
 *
 * The invalidation on a 409 lives in this hook rather than in the conflict handler (3.3):
 * repeating it in the handler double-fetches and forks one policy into two places. Because the
 * availability query stays subscribed on the later steps, the refetch fires at once.
 *
 * The mutation never retries. That is the query client's default for every mutation, since a
 * blind second hold could act twice; the synchronous latch that stops a doubled submit is the
 * flow's, because the latch must be set before the request and read before the handler.
 */

export const HOLDS_PATH = '/api/v1/holds';

/**
 * What the customer typed, owned by the flow rather than by the form, so that a 409 which
 * unmounts the form never costs a retype (1.5).
 */
export interface CustomerDraft {
    readonly name: string;
    readonly email: string;
    readonly phone: string;
    /** "Anything we should know?": optional, for the business's eyes alone. */
    readonly note: string;
}

export const EMPTY_DRAFT: CustomerDraft = { name: '', email: '', phone: '', note: '' };

/** The longest note the hold takes, in characters as the server counts them. */
export const NOTE_MAX = 500;

/**
 * The body as the wire spells it. The phone key is absent rather than empty, and so are the
 * note when nothing but spaces was written and the channel when the mount named none; no
 * same_staff key is sent, every segment carrying the staff member the customer chose for it.
 * There is no source key: the server stamps the source from the request's origin.
 */
export interface HoldBody {
    readonly customer_name: string;
    readonly customer_email: string;
    readonly customer_phone?: string;
    readonly items: readonly AvailabilityItem[];
    readonly start: string;
    readonly note?: string;
    readonly channel?: Channel;
}

export interface Held {
    readonly booking: Booking;
    /** The customer's only credential, which this answer is the one moment of. */
    readonly manageToken: string;
}

export function holdBody(draft: CustomerDraft, items: readonly AvailabilityItem[], start: string, channel: Channel | null): HoldBody {
    const phone = draft.phone.trim();
    const note = draft.note.trim();

    return {
        customer_name: draft.name.trim(),
        customer_email: draft.email.trim(),
        ...(phone === '' ? {} : { customer_phone: phone }),
        items,
        start,
        ...(note === '' ? {} : { note }),
        ...(channel === null ? {} : { channel }),
    };
}

export async function postHold(api: Api, body: HoldBody): Promise<Held> {
    const held = parseHeld(await postJson(api, HOLDS_PATH, body));

    if (held === null) {
        throw new Error(`POST ${HOLDS_PATH} answered something other than a hold.`);
    }

    return held;
}

/**
 * The answer key by key: the booking as a guest is shown it, at least one item, and the token
 * beside it. Anything else is not a hold.
 */
export function parseHeld(value: unknown): Held | null {
    const record = asRecord(value);
    const manageToken = record?.['manage_token'];
    const booking = parseBooking(value);

    if (booking === null || typeof manageToken !== 'string' || manageToken === '') {
        return null;
    }

    return { booking, manageToken };
}

/**
 * The three ways a hold lands, as the flow sees them: a booking to route by its status, a
 * conflict carrying the server's sentence, and any other refusal, each already classified into a
 * notice.
 */
export interface HoldOutcome {
    readonly onHeld: (held: Held) => void;
    readonly onConflict: (notice: Notice) => void;
    readonly onRefused: (notice: Notice) => void;
}

export interface Hold {
    /** True while a hold is on the wire: the visible affordance, one macrotask late. */
    readonly busy: boolean;
    /** The latch, read synchronously, held from before the request until it settles. */
    readonly flying: () => boolean;
    readonly send: (body: HoldBody) => void;
}

/**
 * The mutation, its latch and its one invalidation. The invalidation is availability and only
 * on a 409 (3.3), which is the one refusal that means a rendered offer lost a race; it sits on
 * the mutation rather than on the conflict handler, so no second place can fork the policy.
 *
 * The latch is a ref set before the request and read at the top of send. The rendered disabled
 * attribute is not sufficient: the mutation observer notifies through a deferred scheduler, so
 * disabled reaches the DOM one macrotask late and a second press in that window would detach
 * the first request's callbacks and leave the customer conflicting against their own orphaned
 * hold. It is freed however the answer lands, because a refused hold is one the customer may
 * correct and send again.
 */
export function useHold(api: Api, outcome: HoldOutcome): Hold {
    const client = useQueryClient();
    const flying = useRef(false);
    const mutation = useMutation({
        mutationFn: (body: HoldBody) => postHold(api, body),
        onError: (error) => {
            if (error instanceof ApiError && error.status === 409) {
                void client.invalidateQueries({ queryKey: [AVAILABILITY_KEY] });
            }
        },
    });

    function send(body: HoldBody): void {
        if (flying.current) {
            return;
        }

        flying.current = true;

        mutation.mutate(body, {
            onSuccess: outcome.onHeld,
            onError: (error) => route(error, outcome),
            onSettled: () => {
                flying.current = false;
            },
        });
    }

    return { busy: mutation.isPending, flying: () => flying.current, send };
}

/**
 * The sentence for a hold refused because the business blocked the customer's address from booking
 * online (the 403 with code `blocked`), shown beside the details form in the page's language. The
 * envelope's detail has its Greek twin (api.php envelope.blocked) but is the API's plain answer to
 * any caller, that booking online is not available; the client keeps a line of its own because
 * beside the form it can say what the visitor can do: the address typed there is the one refused,
 * and the business is the one to contact.
 */
export const BLOCKED_SENTENCE: LineKey = 'details.blocked';

/** The code of that refusal. */
const BLOCKED_CODE = 'blocked';

/**
 * What a refused hold says beside the form: the client's own sentence for a blocked address,
 * else the refusal classified by 4.1 into its politeness, so a 400 or a 429 is polite and a
 * transport failure is the fixed alert.
 */
export function holdRefusal(error: unknown): Notice {
    return error instanceof ApiError && error.status === 403 && error.code === BLOCKED_CODE ? { key: BLOCKED_SENTENCE, tone: 'polite' } : classify(error);
}

/**
 * Where a refused hold goes: a 409 is the conflict path of 4.3 and carries the server's
 * sentence verbatim, polite as every 4xx, the app's own where the answer carried none;
 * everything else is a sentence beside the form, holdRefusal's.
 */
function route(error: unknown, outcome: HoldOutcome): void {
    if (error instanceof ApiError && error.status === 409) {
        outcome.onConflict(classify(error));

        return;
    }

    outcome.onRefused(holdRefusal(error));
}
