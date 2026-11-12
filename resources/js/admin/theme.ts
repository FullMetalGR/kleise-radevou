/*
 * The theme, remembered per machine. Every storage read and write is wrapped: a browser
 * blocking site data costs the memory and never the screen, 9.3, so a failed read falls back to
 * light and a failed write is swallowed, and the theme still switches for the session. Reaching
 * localStorage at all can throw where site data is blocked, so the storage is handed in as a
 * getter and the getter is called inside the guard.
 */

export type Theme = 'light' | 'dark';

export const THEME_KEY = 'kr.admin.theme';

export function readTheme(storage: () => Storage): Theme {
    try {
        return storage().getItem(THEME_KEY) === 'dark' ? 'dark' : 'light';
    } catch {
        return 'light';
    }
}

export function writeTheme(theme: Theme, storage: () => Storage): void {
    try {
        storage().setItem(THEME_KEY, theme);
    } catch {
        // Swallowed on purpose: the choice holds for this session and is not remembered.
    }
}

export function applyTheme(theme: Theme, root: HTMLElement): void {
    root.classList.toggle('dark', theme === 'dark');
}
