import { useEffect, useRef, useState } from 'react';
import { useShell } from '@/lib/strings';
import { useToasts } from '@/toast/toasts';
import { type Failure, owningFailure as owning } from './visits';

/*
 * One busy state over a page's own writes: a write starts only while none is in flight; the
 * action in flight is what the page reads to mark the pressed control busy and to hold every
 * other one with that write's sentence; a refusal is the sentence the caller words from the
 * failure, in an error toast that stays until dismissed, unless the caller shows it itself and
 * words it null, which the Payments section does for the 429 of its limited writes, a warning
 * notice with role status. The caller names each action's sentence, "Please wait until ...". The
 * Settings screen holds its writes so, and the staff detail page its Save and its photo's two
 * writes.
 *
 * A write answered with a location leaves the page: the router assigns the window's address and
 * ends the visit with no page, no success and no refusal, and the provider's page replaces this
 * one a moment later. That write stays in flight until the page goes, so nothing is pressed in
 * that moment; a visit the router cancelled gives the controls back. A page the browser kept in
 * its back/forward cache comes back on Back as it was left, the hold included, with the
 * navigation that held it long over: the browser says so with a pageshow event marked persisted,
 * and the hook frees the write that left on it, so the page is live again. A write still in
 * flight on such a restore is left to its own end.
 */

/** A visit's sender: the caller's router call, handed the options that track it. */
export type Send = (options: Record<string, unknown>) => void;

/** What the router hands a visit's end: whether it was cancelled or interrupted rather than answered. */
type Ended = { cancelled?: boolean; interrupted?: boolean } | undefined;

export type OneWrite<A extends string> = {
    inFlight: A | null;
    /** The sentence of the write in flight, or null while none is. */
    pending: string | null;
    /** The sentence that holds the given action's own control: the write in flight's, unless it is that action. */
    waitFor: (action: A) => string | null;
    /** Runs the write; word answers a refusal's toast sentence, or null where the caller has shown it. */
    visit: (action: A, word: (failure: Failure) => string | null, onSuccess: () => void, send: Send) => void;
};

export function useOneWrite<A extends string>(wait: Record<A, string>): OneWrite<A> {
    const toasts = useToasts();
    const shell = useShell();
    const [inFlight, setInFlight] = useState<A | null>(null);
    // Whether the write in flight left the page on a location answer, held until the page goes.
    const left = useRef(false);
    const pending = inFlight === null ? null : wait[inFlight];

    useEffect(() => {
        const restored = (event: PageTransitionEvent) => {
            if (event.persisted && left.current) {
                left.current = false;
                setInFlight(null);
            }
        };
        window.addEventListener('pageshow', restored);

        return () => window.removeEventListener('pageshow', restored);
    }, []);

    const visit = (action: A, word: (failure: Failure) => string | null, onSuccess: () => void, send: Send) => {
        if (inFlight !== null) {
            return;
        }

        let answered = false;
        const owned = owning((failure) => {
            answered = true;
            const sentence = word(failure);

            if (sentence !== null) {
                toasts.push('error', sentence);
            }
        }, shell);

        send({
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                left.current = false;
                setInFlight(action);
                owned.onStart();
            },
            onSuccess: () => {
                answered = true;
                onSuccess();
            },
            onError: () => {
                answered = true;
            },
            onFinish: (ended: Ended) => {
                owned.onFinish();

                if (answered || ended?.cancelled === true || ended?.interrupted === true) {
                    setInFlight(null);
                } else {
                    left.current = true;
                }
            },
        });
    };

    return { inFlight, pending, waitFor: (action) => (inFlight === action ? null : pending), visit };
}
