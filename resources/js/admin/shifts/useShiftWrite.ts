import { useState } from 'react';
import { useShell } from '@/lib/strings';
import { owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';

/*
 * The one write of a Shifts modal, the day editor's and the pattern editor's: a write starts only
 * while none is in flight, and the action in flight is what the modal reads to mark its pressed
 * control busy and to hold the others, Cancel, Escape and a backdrop press among them. Nothing is
 * optimistic: the server's answer closes the modal, the redirect back redrawing the page from its
 * props, and a refusal is the envelope's sentence, else the modal's own, as an error toast, the
 * modal kept open with what was entered.
 */

/** A visit's sender: the caller's router call, handed the options that track it. */
type Send = (options: Record<string, unknown>) => void;

type ShiftWrite<A extends string> = {
    /** The action in flight, or null while none is. */
    pending: A | null;
    write: (action: A, send: Send) => void;
};

/** The modal's write, its refusal worded `notSaved` where the server worded none, `onClose` run on success. */
export function useShiftWrite<A extends string>(notSaved: string, onClose: () => void): ShiftWrite<A> {
    const shell = useShell();
    const toasts = useToasts();
    const [pending, setPending] = useState<A | null>(null);

    const write = (action: A, send: Send) => {
        if (pending !== null) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? notSaved), shell);

        send({
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                setPending(action);
                owned.onStart();
            },
            onSuccess: () => onClose(),
            onFinish: () => {
                setPending(null);
                owned.onFinish();
            },
        });
    };

    return { pending, write };
}
