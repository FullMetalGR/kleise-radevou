import { router } from '@inertiajs/react';
import { type Dispatch, type SetStateAction, useState } from 'react';
import { owning } from '@/lib/visits';

/*
 * The one read the Bookings list, and the two customers screens make of their list, 3.9, and the
 * Reports screen of its figures, 3.10: a partial reload of the props named, at the URL and query
 * given, the URL replaced so the query is what a reload or a bookmark lands on, and the busy and
 * failed states the screen reads. Nothing is optimistic: what is on screen stays the server's
 * last answer until the next one lands, and the visit owns its failure.
 */

export type PartialRead = {
    loading: boolean;
    error: boolean;
    read: (url: string, query: Record<string, string>) => void;
};

export function usePartialRead(only: readonly string[]): PartialRead {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);

    const read = (url: string, query: Record<string, string>) => {
        const owned = owning(() => setError(true));

        router.get(url, query, {
            only: [...only],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                setLoading(true);
                owned.onStart();
            },
            onSuccess: () => setError(false),
            onFinish: () => {
                setLoading(false);
                owned.onFinish();
            },
        });
    };

    return { loading, error, read };
}

/**
 * What a list's bar holds, seeded from the server's first answer and brought back into line with
 * each later one: the bar holds what was asked and the rows what the server answered, and where
 * the server read the ask otherwise, a pair of dates it dropped or a value it could not read, the
 * bar says so rather than holding a filter the rows do not answer. An answer is a new object of
 * the server's filters; it is taken only once no read is in flight, so an answer landing while a
 * newer read runs is that read's to settle and a person typing is never overtaken. How a field
 * settles is the screen's: keepTyped below for text the server trims.
 */
export function useAnsweredBar<Bar>(answer: object, seed: Bar, loading: boolean, settle: (bar: Bar) => Bar): [Bar, Dispatch<SetStateAction<Bar>>] {
    const [bar, setBar] = useState(seed);
    const [seen, setSeen] = useState(answer);

    if (seen !== answer && !loading) {
        setSeen(answer);
        setBar(settle);
    }

    return [bar, setBar];
}

/**
 * A text field as an answer settles it: what was typed where the server read it trimmed, the
 * server's reading otherwise.
 */
export function keepTyped(typed: string, served: string): string {
    return typed.trim() === served ? typed : served;
}
