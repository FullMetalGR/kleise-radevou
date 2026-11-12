import type { MouseEvent } from 'react';

/*
 * A link a screen also follows in the page, a list's name opening its detail by a visit: a plain
 * left click is the screen's, and a click with Ctrl, Cmd or Shift held, or with another button,
 * is the browser's, opening the link's address in a new tab or window as any link does. Either
 * way the click goes no further than the link, so the row around it opens nothing.
 */
export function inPage(event: MouseEvent, open: () => void): void {
    event.stopPropagation();

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
        return;
    }

    event.preventDefault();
    open();
}
