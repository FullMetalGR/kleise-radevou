import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useShell } from '@/lib/strings';
import { owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import type { ColourBy } from './model';

/*
 * Colour by's write: one word to the settings row on its own route, busy until the server answers
 * and nothing optimistic, the redirect landing the new word in the shared props; a failure toasted
 * in the answer's sentence or the caller's own. The Calendar's filter row and the Staff screen's
 * Calendar colours card both write through it.
 */

/** PUT /admin/settings/colour-by. */
const COLOUR_BY_PATH = '/admin/settings/colour-by';

/** The write, and whether it is in flight; `failed` is the caller's sentence where no answer came. */
export function useColourByWrite(failed: string): { busy: boolean; write: (word: ColourBy) => void } {
    const shell = useShell();
    const toasts = useToasts();
    const [busy, setBusy] = useState(false);

    const write = (word: ColourBy) => {
        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? failed), shell);

        router.put(
            COLOUR_BY_PATH,
            { colour_by: word },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    owned.onStart();
                },
                onFinish: () => {
                    setBusy(false);
                    owned.onFinish();
                },
            },
        );
    };

    return { busy, write };
}
