import { useSyncExternalStore } from 'react';

/*
 * The admin's one breakpoint. The stylesheet declares it once, as a theme token Tailwind turns
 * into the desktop: and max-desktop: variants and writes onto the root, and this reads it off
 * the root by its name, so no component spells its number. At or above it the admin is the
 * desktop layout; below it, the phone layout, where a branch is React's to take rather than a
 * class's: the rail behind its control, Week's notice in place of the grid. The query is the one
 * the build writes for the desktop: variant, min-width, so the two cannot disagree.
 *
 * Where the environment answers no media query, or the token is not on the root, the answer is
 * the desktop: what cannot measure the viewport is never handed the phone layout by accident.
 */

export const BREAKPOINT = '--breakpoint-desktop';

function desktopQuery(): MediaQueryList | null {
    if (typeof window.matchMedia !== 'function') {
        return null;
    }

    const width = getComputedStyle(document.documentElement).getPropertyValue(BREAKPOINT).trim();

    return width === '' ? null : window.matchMedia(`(min-width: ${width})`);
}

function subscribe(changed: () => void): () => void {
    const query = desktopQuery();

    query?.addEventListener('change', changed);

    return () => query?.removeEventListener('change', changed);
}

function snapshot(): boolean {
    return desktopQuery()?.matches ?? true;
}

/** Whether the viewport is at or above the admin's breakpoint, following it as it moves. */
export function useDesktop(): boolean {
    return useSyncExternalStore(subscribe, snapshot);
}
