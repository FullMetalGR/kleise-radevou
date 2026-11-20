import { useSyncExternalStore } from 'react';

/*
 * The admin's two breakpoints. The stylesheet declares each once, as a theme token Tailwind turns
 * into a pair of variants and writes onto the root, and this reads them off the root by name, so
 * no component spells a number.
 *
 * The desktop breakpoint splits the phone from everything wider. At or above it the admin is the
 * desktop layout; below it, the phone layout, where a branch is React's to take rather than a
 * class's: Week's notice in place of the grid, the help panel's sheet. The wide breakpoint splits
 * the full shell from the compact one: at or above it the sidebar stands beside the page; below
 * it, the menu waits behind its button at the start of the page's first row. Each query is the
 * one the build writes for its variant, min-width, so the two cannot disagree.
 *
 * Where the environment answers no media query, or a token is not on the root, the answer is yes:
 * what cannot measure the viewport is never handed the smaller layout by accident.
 */

export const BREAKPOINT = '--breakpoint-desktop';

export const WIDE = '--breakpoint-wide';

type Watch = { subscribe: (changed: () => void) => () => void; snapshot: () => boolean };

function watch(token: string): Watch {
    const query = (): MediaQueryList | null => {
        if (typeof window.matchMedia !== 'function') {
            return null;
        }

        const width = getComputedStyle(document.documentElement).getPropertyValue(token).trim();

        return width === '' ? null : window.matchMedia(`(min-width: ${width})`);
    };

    return {
        subscribe: (changed) => {
            const list = query();

            list?.addEventListener('change', changed);

            return () => list?.removeEventListener('change', changed);
        },
        snapshot: () => query()?.matches ?? true,
    };
}

const desktop = watch(BREAKPOINT);

const wide = watch(WIDE);

/** Whether the viewport is at or above the desktop breakpoint, following it as it moves. */
export function useDesktop(): boolean {
    return useSyncExternalStore(desktop.subscribe, desktop.snapshot);
}

/** Whether the viewport is at or above the wide breakpoint, where the sidebar stands beside the page. */
export function useWide(): boolean {
    return useSyncExternalStore(wide.subscribe, wide.snapshot);
}
