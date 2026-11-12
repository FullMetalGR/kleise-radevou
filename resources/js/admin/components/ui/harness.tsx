import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { act, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { Language } from '../../../booking/language';
import { BREAKPOINT } from '@/lib/viewport';
import { WithShell } from '@/testing/keyed';

/*
 * The one mount the control tests share: a host appended to the document, so focus and portals
 * behave as they do on a page, and an unmount that leaves the document as it was found. Every
 * render sits inside the shell's keyed table, as a page sits inside the shell's words, so a
 * component's word reads as its own key in brackets and a test reads what it expects through
 * keyedShell(), never a sentence; English unless a language is named.
 */

export type Mounted = {
    host: HTMLDivElement;
    render: (node: ReactNode) => void;
    unmount: () => void;
};

export function mount(language: Language = 'en'): Mounted {
    const host = document.createElement('div');
    document.body.append(host);
    const root: Root = createRoot(host);

    return {
        host,
        render: (node) => act(() => root.render(<WithShell language={language}>{node}</WithShell>)),
        unmount: () => {
            act(() => root.unmount());
            host.remove();
        },
    };
}

/** A click as a pointer produces it, bubbling, inside act. */
export function press(element: Element | null): void {
    if (element === null) {
        throw new Error('Nothing to press.');
    }

    act(() => element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })));
}

/** A key pressed on an element, bubbling, inside act. */
export function key(element: Element | null, name: string, init: KeyboardEventInit = {}): void {
    if (element === null) {
        throw new Error('Nothing to press a key on.');
    }

    act(() => element.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true, ...init })));
}

/**
 * A primary-button press as a browser delivers it: pointerdown and mousedown where it starts,
 * pointerup and mouseup where it is released, and a click on the nearest element holding both.
 */
export function drag(from: Element | null, to: Element | null): void {
    if (from === null || to === null) {
        throw new Error('Nowhere to press.');
    }

    let common: Element | null = from;

    while (common !== null && !common.contains(to)) {
        common = common.parentElement;
    }

    const init = { bubbles: true, cancelable: true, button: 0 };

    act(() => {
        from.dispatchEvent(new PointerEvent('pointerdown', init));
        from.dispatchEvent(new MouseEvent('mousedown', init));
    });
    act(() => {
        to.dispatchEvent(new PointerEvent('pointerup', init));
        to.dispatchEvent(new MouseEvent('mouseup', init));
        common?.dispatchEvent(new MouseEvent('click', init));
    });
}

/**
 * One task passed inside act, for what the primitives defer to the next task: the focus scope
 * hands focus back that way once a modal or a popover has closed, and a popover starts hearing
 * presses outside it that way once it has opened.
 */
export async function settle(): Promise<void> {
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
}

/**
 * The breakpoint's width as the admin's stylesheet declares it, read from app.css, the one place
 * it is spelled, so no test spells the number beside it. Throws where the stylesheet declares no
 * such token, rather than handing a test an empty width.
 */
export function desktopWidth(): string {
    const css = readFileSync(join(process.cwd(), 'resources/js/admin/app.css'), 'utf8');
    const width = new RegExp(`${BREAKPOINT}\\s*:\\s*([^;]+);`).exec(css)?.[1]?.trim();

    if (width === undefined || width === '') {
        throw new Error(`The admin's stylesheet declares no ${BREAKPOINT}.`);
    }

    return width;
}

/**
 * The admin below its breakpoint, as useDesktop reads it: the token on the root, as the
 * stylesheet puts it there, and every media query answering no. Answers its own undoing, which an
 * afterEach calls, so a test that forgets cannot leave the next one on a phone.
 */
export function onPhone(): () => void {
    const original = window.matchMedia;
    const root = document.documentElement;

    window.matchMedia = (query: string) =>
        ({
            matches: false,
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
        }) as unknown as MediaQueryList;
    root.style.setProperty(BREAKPOINT, desktopWidth());

    return () => {
        window.matchMedia = original;
        root.style.removeProperty(BREAKPOINT);
    };
}
