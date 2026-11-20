/*
 * Whether the sidebar is folded to its strip of icons, remembered per device as the theme is.
 * Every storage read and write is wrapped: a browser blocking site data costs the memory and never
 * the sidebar, so a failed read starts it open and a failed write is swallowed, and the button
 * still folds and opens it for the visit. Reaching localStorage at all can throw where site data
 * is blocked, so the storage is handed in as a getter and the getter is called inside the guard.
 */

export const COLLAPSED_KEY = 'kr.admin.sidebar';

const COLLAPSED = 'collapsed';

export function readCollapsed(storage: () => Storage): boolean {
    try {
        return storage().getItem(COLLAPSED_KEY) === COLLAPSED;
    } catch {
        return false;
    }
}

export function writeCollapsed(collapsed: boolean, storage: () => Storage): void {
    try {
        storage().setItem(COLLAPSED_KEY, collapsed ? COLLAPSED : 'open');
    } catch {
        // Swallowed on purpose: the choice holds for this visit and is not remembered.
    }
}
