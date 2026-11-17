import type { Failure } from '@/lib/visits';

/*
 * Where a step's refusal is said: on its field, by the house's Field error, where the envelope
 * names a body field the step draws; in the error toast where it names none, or one the step does
 * not draw, or no answer came. The table maps each body field the step sends to the form field
 * that draws it, since a body field is not always named as its control is: the price is sent in
 * minor units as price_minor.
 */

/** A one-write word: shows a field's refusal and answers null, or answers the toast's sentence. */
export function onField<F extends string>(fields: Readonly<Record<string, F>>, show: (field: F, sentence: string) => void, fallback: string): (failure: Failure) => string | null {
    return (failure) => {
        const named = failure.field;

        if (named !== null && failure.sentence !== null && Object.hasOwn(fields, named)) {
            show(fields[named] as F, failure.sentence);

            return null;
        }

        return failure.sentence ?? fallback;
    };
}

/** The refusals left once a field is edited: its own is forgotten, the others kept. */
export function forgetting<F extends string>(refused: Readonly<Partial<Record<F, string>>>, field: F): Partial<Record<F, string>> {
    const kept: Partial<Record<F, string>> = { ...refused };
    delete kept[field];

    return kept;
}
