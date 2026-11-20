import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { act, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { Language } from '../../../booking/language';
import { BREAKPOINT, WIDE } from '@/lib/viewport';
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

/** A click as a pointer produces it, bubbling, inside act; with a key held where one is named. */
export function press(element: Element | null, init: MouseEventInit = {}): void {
    if (element === null) {
        throw new Error('Nothing to press.');
    }

    act(() => element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ...init })));
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

/** One of the browser's drag and drop events, bubbling inside act, with a transfer to carry; the event, to read whether it was taken. */
export function dragEvent(target: Element | null, type: string, relatedTarget: Element | null = null): Event {
    if (target === null) {
        throw new Error(`Nothing to fire ${type} on.`);
    }

    const event = new MouseEvent(type, { bubbles: true, cancelable: true, relatedTarget });
    Object.defineProperty(event, 'dataTransfer', { value: { effectAllowed: 'all', dropEffect: 'none', setData: () => undefined, getData: () => '' } });
    act(() => {
        target.dispatchEvent(event);
    });

    return event;
}

/** An element dragged by the browser's drag and drop onto another and let go there; whether the target took it, as its drag over said. */
export function carry(from: Element | null, to: Element | null): boolean {
    dragEvent(from, 'dragstart');
    const over = dragEvent(to, 'dragover');
    dragEvent(to, 'drop');
    dragEvent(from, 'dragend');

    return over.defaultPrevented;
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
 * A breakpoint's width as the admin's stylesheet declares it, read from app.css, the one place it
 * is spelled, so no test spells the number beside it. Throws where the stylesheet declares no such
 * token, rather than handing a test an empty width.
 */
function declaredWidth(token: string): string {
    const css = readFileSync(join(process.cwd(), 'resources/js/admin/app.css'), 'utf8');
    const width = new RegExp(`${token}\\s*:\\s*([^;]+);`).exec(css)?.[1]?.trim();

    if (width === undefined || width === '') {
        throw new Error(`The admin's stylesheet declares no ${token}.`);
    }

    return width;
}

/** The desktop breakpoint's width, as the stylesheet declares it. */
export function desktopWidth(): string {
    return declaredWidth(BREAKPOINT);
}

/** The wide breakpoint's width, as the stylesheet declares it. */
export function wideWidth(): string {
    return declaredWidth(WIDE);
}

/**
 * The viewport the hooks read, put where a test wants it: both tokens on the root, as the
 * stylesheet puts them there, and every media query answering whether its width is one of those
 * given. Answers its own undoing, which an afterEach calls, so a test that forgets cannot leave
 * the next one on a smaller screen.
 */
function viewport(wider: string[]): () => void {
    const original = window.matchMedia;
    const root = document.documentElement;

    window.matchMedia = (query: string) =>
        ({
            matches: wider.some((width) => query === `(min-width: ${width})`),
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
        }) as unknown as MediaQueryList;
    root.style.setProperty(BREAKPOINT, desktopWidth());
    root.style.setProperty(WIDE, wideWidth());

    return () => {
        window.matchMedia = original;
        root.style.removeProperty(BREAKPOINT);
        root.style.removeProperty(WIDE);
    };
}

/** The admin on a phone: below both breakpoints, as useDesktop and useWide read them. */
export function onPhone(): () => void {
    return viewport([]);
}

/**
 * The admin on a screen at or above the desktop breakpoint and below the wide one, a tablet or a
 * narrow window: the desktop layout inside the compact shell.
 */
export function onCompact(): () => void {
    return viewport([desktopWidth()]);
}
