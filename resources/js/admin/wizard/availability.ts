import { useEffect, useState } from 'react';
import { sentenceOf } from '@/lib/sentence';
import { type ShellWords, useShell } from '@/lib/strings';
import type { WizardKey, WizardWords } from '@/customers/words';

/*
 * The wizard's one fetch: GET /admin/api/availability over the session, the public availability
 * read's request and answer. The admin fetches JSON here and in one place besides, the export
 * buttons' download (lib/download.ts), which fetches a file to save rather than data to show; every
 * other screen stays on Inertia props. The query is keyed on the chain, the range and the
 * same-staff preference, and asked only while it is non-null, which is once every segment names a
 * service. An answer arriving for a query no longer asked is dropped.
 *
 * A refusal is a sentence and never a silent empty state: a 4xx answers with its envelope's detail,
 * a lapsed session among them (the server answers a JSON caller 401 rather than sending it to the
 * sign-in page), and a 200 whose body is not the day's list, which is what a page answered in place
 * of the JSON would read as, is an error with its own sentence rather than a day with no times. The
 * server answers every day asked for as a list, so a missing list is an answer of the wrong shape
 * and not an empty day.
 */

export const AVAILABILITY_URL = '/admin/api/availability';

export type Times = { kind: 'idle' } | { kind: 'loading' } | { kind: 'error'; sentence: string } | { kind: 'ready'; starts: string[] };

const IDLE: Times = { kind: 'idle' };

const LOADING: Times = { kind: 'loading' };

/** The sentence for an OK answer that is not the availability shape, a key of the wizard's words. */
export const UNREADABLE = 'unreadable' satisfies WizardKey;

/** The day's list as the answer carries it, or null where the answer is not that shape. */
function startsOf(answer: unknown, day: string): string[] | null {
    const starts: unknown = typeof answer === 'object' && answer !== null ? (answer as Record<string, unknown>)[day] : undefined;

    return Array.isArray(starts) && starts.every((start): start is string => typeof start === 'string') ? starts : null;
}

function timesOf(ok: boolean, answer: unknown, day: string, shell: ShellWords, words: WizardWords): Times {
    if (!ok) {
        return { kind: 'error', sentence: sentenceOf(answer, shell) };
    }

    const starts = startsOf(answer, day);

    return starts === null ? { kind: 'error', sentence: words.text(UNREADABLE) } : { kind: 'ready', starts };
}

export function useAvailability(query: string | null, day: string, timezone: string, words: WizardWords): Times {
    const shell = useShell();
    const [times, setTimes] = useState<Times>(IDLE);

    useEffect(() => {
        if (query === null) {
            setTimes(IDLE);

            return undefined;
        }

        let current = true;
        setTimes(LOADING);

        fetch(`${AVAILABILITY_URL}?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (response): Promise<Times> => timesOf(response.ok, await response.json().catch(() => null), day, shell, words))
            .catch((failure: unknown): Times => ({ kind: 'error', sentence: sentenceOf(failure, shell) }))
            .then((next) => {
                if (current) {
                    setTimes(next);
                }
            });

        return () => {
            current = false;
        };
    }, [query, day, timezone]);

    return times;
}
