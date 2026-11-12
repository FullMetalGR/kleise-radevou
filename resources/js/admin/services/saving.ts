import { useSyncExternalStore } from 'react';

/*
 * Whether a save of the Services screen is in flight, held outside the page because a create
 * lands on the new row's page, which is a new route and a fresh mount of the page, while the
 * staff link writes that follow the patch are still being sent one by one. The fresh page reads
 * this and shows the save busy until the last write lands, so nothing offers a second save over
 * one that has not finished.
 */

let busy = false;

const listeners = new Set<() => void>();

export const saving = {
    get: (): boolean => busy,
    set: (value: boolean): void => {
        busy = value;
        listeners.forEach((listener) => listener());
    },
    subscribe: (listener: () => void): (() => void) => {
        listeners.add(listener);

        return () => {
            listeners.delete(listener);
        };
    },
};

export function useSaving(): boolean {
    return useSyncExternalStore(saving.subscribe, saving.get);
}
