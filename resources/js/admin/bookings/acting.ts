import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useShell } from '@/lib/strings';
import { type Failure, owningFailure } from '@/lib/visits';
import type { Act } from './ActionBar';
import type { BookingsKey, BookingsWords } from './words';

/*
 * The dialog's actions as a screen posts them, shared by the Bookings screen and the Calendar:
 * a form post the server answers with a redirect back and a flash, so the list or the window,
 * the detail and the rail all arrive re-read from the server's answer and nothing here is
 * assumed. One act at a time, busy until the visit ends; a failure is worded here for the
 * dialog to show: a refusal the server worded in the error envelope in its own sentence, as
 * Reject's refused body is, a 403 in the dialog's sentence for it, anything else in the one
 * that sends the owner to look again. Both of the dialog's own sentences are keys of the
 * bookings section the screen hands in.
 */

const LIST = '/admin/bookings';

const ACT_ERROR: BookingsKey = 'acting.error';

const ACT_FORBIDDEN: BookingsKey = 'acting.forbidden';

/** The sentence the dialog shows for a failed act: the envelope's own, where the answer is one. */
export function sentenceFor(failure: Failure, words: BookingsWords): string {
    if (failure.code !== null && failure.sentence !== null) {
        return failure.sentence;
    }

    return words.text(failure.status === 403 ? ACT_FORBIDDEN : ACT_ERROR);
}

export type Acts = {
    /** The action whose visit is in flight, or null. */
    busy: string | null;
    /** The last failure's sentence, or null; the screen clears it when the dialog opens or closes. */
    failure: string | null;
    clear: () => void;
    act: (uuid: string) => Act;
};

export function useActs(words: BookingsWords): Acts {
    const shell = useShell();
    const [busy, setBusy] = useState<string | null>(null);
    const [failure, setFailure] = useState<string | null>(null);

    const act = (uuid: string): Act => (action, body, done) => {
        if (busy !== null) {
            return;
        }

        const owned = owningFailure((failure) => setFailure(sentenceFor(failure, words)), shell);

        setFailure(null);
        router.post(`${LIST}/${encodeURIComponent(uuid)}/${encodeURIComponent(action)}`, body, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                setBusy(action);
                owned.onStart();
            },
            onFinish: () => {
                setBusy(null);
                owned.onFinish();
                done();
            },
        });
    };

    return { busy, failure, clear: () => setFailure(null), act };
}
