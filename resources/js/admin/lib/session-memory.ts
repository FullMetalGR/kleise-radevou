/*
 * Filter memory per browser tab: a value written to sessionStorage and read back field by field
 * with a shape check, so a saved value of the wrong type falls back to that field's default rather
 * than putting an inexpressible value in a control. Every read and write is wrapped: a browser
 * blocking site data costs the memory and never the screen. Used by the Bookings bar here and by
 * the Calendar filters.
 */

export type Shape<T> = { readonly [K in keyof T]: (value: unknown) => value is T[K] };

export function readMemory<T extends object>(key: string, shape: Shape<T>, defaults: T, storage: () => Storage): T {
    let saved: unknown;

    try {
        const raw = storage().getItem(key);
        saved = raw === null ? null : JSON.parse(raw);
    } catch {
        return defaults;
    }

    if (typeof saved !== 'object' || saved === null) {
        return defaults;
    }

    const restored = { ...defaults };

    for (const field of Object.keys(shape) as (keyof T)[]) {
        const value: unknown = (saved as Record<PropertyKey, unknown>)[field];

        if (shape[field](value)) {
            restored[field] = value;
        }
    }

    return restored;
}

export function writeMemory(key: string, value: object, storage: () => Storage): void {
    try {
        storage().setItem(key, JSON.stringify(value));
    } catch {
        // The memory is lost; the screen keeps working for this visit.
    }
}
