import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Button } from './button';
import { drag, key, mount, press, settle, type Mounted } from './harness';
import { Modal } from './modal';

/*
 * The modal, and Escape across stacked dialogs. The modal is a backdrop and a frame with the
 * dialog role, aria-modal and a title; an optional footer pins its actions outside the scrolling
 * body; focus moves into the frame on open and back to the opener on close; Escape closes; a
 * backdrop press closes only when it both starts and ends on the backdrop. Stacked, a parent
 * ignores close requests while a nested overlay is up, so one Escape peels exactly one layer: a
 * nested modal is a layer of its own in the primitive's stack. A popover inside a modal is proven
 * in the popover's own suite. Focus goes back once the primitive's focus scope has let go, a task
 * after the close, so the tests that look for it settle first.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function Single({ onClose }: { onClose?: () => void }) {
    const [open, setOpen] = useState(false);
    const close = () => {
        onClose?.();
        setOpen(false);
    };

    return (
        <>
            <button type="button" className="opener" onClick={() => setOpen(true)}>
                Open
            </button>
            <Modal
                open={open}
                title="Reject this booking?"
                onClose={close}
                footer={
                    <Button variant="primary" className="confirm" onClick={close}>
                        Reject
                    </Button>
                }
            >
                <textarea className="reason" />
                <p className="long">A long audit trail.</p>
            </Modal>
        </>
    );
}

function Stacked() {
    const [outer, setOuter] = useState(true);
    const [inner, setInner] = useState(false);

    return (
        <Modal open={outer} title="Maria Papadopoulou" onClose={() => setOuter(false)}>
            <button type="button" className="open-inner" onClick={() => setInner(true)}>
                Reject
            </button>
            <Modal open={inner} title="Reject this booking?" onClose={() => setInner(false)}>
                <button type="button" className="inner-action">
                    Keep it
                </button>
            </Modal>
        </Modal>
    );
}

const dialogs = () => [...document.querySelectorAll('[role="dialog"]')];
const frame = () => document.querySelector('.admin-modal__frame');
const backdrop = () => document.querySelector('.admin-modal__backdrop');

function openSingle(onClose?: () => void): HTMLElement {
    view.render(<Single onClose={onClose} />);

    const opener = view.host.querySelector<HTMLElement>('.opener');

    opener?.focus();
    press(opener);

    if (opener === null) {
        throw new Error('No opener.');
    }

    return opener;
}

describe('the modal', () => {
    it('renders nothing while closed', () => {
        view.render(<Single />);

        expect(dialogs()).toHaveLength(0);
    });

    it('is a dialog, modal, labelled by its title', () => {
        openSingle();

        const dialog = dialogs()[0];
        const title = document.getElementById(dialog?.getAttribute('aria-labelledby') ?? '');

        expect(dialogs()).toHaveLength(1);
        expect(dialog?.getAttribute('aria-modal')).toBe('true');
        expect(title?.textContent).toBe('Reject this booking?');
    });

    it('moves focus inside the frame on open and back to the opener on close', async () => {
        const opener = openSingle();

        expect(frame()?.contains(document.activeElement)).toBe(true);

        press(document.querySelector('.confirm'));
        await settle();

        expect(dialogs()).toHaveLength(0);
        expect(document.activeElement).toBe(opener);
    });

    it('closes on Escape', async () => {
        const onClose = vi.fn();
        const opener = openSingle(onClose);

        key(document.activeElement, 'Escape');
        await settle();

        expect(onClose).toHaveBeenCalledOnce();
        expect(dialogs()).toHaveLength(0);
        expect(document.activeElement).toBe(opener);
    });

    it('ignores an Escape that is composing text', () => {
        const onClose = vi.fn();

        openSingle(onClose);
        key(document.querySelector('.reason'), 'Escape', { isComposing: true });

        expect(onClose).not.toHaveBeenCalled();
    });

    it('does not close on a press that starts on the backdrop and is released over the frame', async () => {
        const onClose = vi.fn();

        openSingle(onClose);
        await settle();
        drag(backdrop(), document.querySelector('.reason'));

        expect(onClose).not.toHaveBeenCalled();
    });

    it('does not close on a press that starts in the frame and is released over the backdrop', async () => {
        const onClose = vi.fn();

        openSingle(onClose);
        await settle();
        drag(document.querySelector('.reason'), backdrop());

        expect(onClose).not.toHaveBeenCalled();
    });

    it('closes on a press both down and up on the backdrop', async () => {
        const onClose = vi.fn();

        openSingle(onClose);
        await settle();
        drag(backdrop(), backdrop());

        expect(onClose).toHaveBeenCalledOnce();
        expect(dialogs()).toHaveLength(0);
    });

    it('pins the footer outside the scrolling body, in a frame bounded to the viewport', () => {
        openSingle();

        const body = document.querySelector('.admin-modal__body');
        const footer = document.querySelector('.admin-modal__footer');

        expect(body?.contains(document.querySelector('.long'))).toBe(true);
        expect(body?.classList.contains('overflow-y-auto')).toBe(true);
        expect(body?.classList.contains('min-h-0')).toBe(true);
        expect(footer?.contains(document.querySelector('.confirm'))).toBe(true);
        expect(body?.contains(footer ?? null)).toBe(false);
        expect(footer?.classList.contains('shrink-0')).toBe(true);
        expect(frame()?.classList.contains('flex-col')).toBe(true);
        expect([...(frame()?.classList ?? [])].some((name) => name.startsWith('max-h-'))).toBe(true);
    });

    it('keeps Tab inside the frame, wrapping at both ends', () => {
        openSingle();

        const reason = document.querySelector<HTMLElement>('.reason');
        const confirm = document.querySelector<HTMLElement>('.confirm');

        confirm?.focus();
        key(confirm, 'Tab');
        expect(frame()?.contains(document.activeElement)).toBe(true);
        expect(document.activeElement).not.toBe(confirm);

        reason?.focus();
        key(reason, 'Tab', { shiftKey: true });
        key(document.activeElement, 'Tab', { shiftKey: true });
        expect(frame()?.contains(document.activeElement)).toBe(true);
    });
});

describe('two stacked modals', () => {
    function openBoth(): void {
        view.render(<Stacked />);

        const opener = document.querySelector<HTMLElement>('.open-inner');

        opener?.focus();
        press(opener);
        expect(dialogs()).toHaveLength(2);
    }

    const titles = () => dialogs().map((dialog) => document.getElementById(dialog.getAttribute('aria-labelledby') ?? '')?.textContent);

    it('peels one layer per Escape: the top one first, then the other', () => {
        openBoth();

        key(document.querySelector('.inner-action'), 'Escape');
        expect(titles()).toEqual(['Maria Papadopoulou']);

        key(document.activeElement ?? document.body, 'Escape');
        expect(dialogs()).toHaveLength(0);
    });

    it('returns focus from the top one to its opener inside the other', async () => {
        openBoth();

        key(document.querySelector('.inner-action'), 'Escape');
        await settle();

        expect(document.activeElement).toBe(document.querySelector('.open-inner'));
    });

    it('does not let the parent close on a backdrop press made on the top one', async () => {
        openBoth();
        await settle();

        const top = [...document.querySelectorAll('.admin-modal__backdrop')].at(-1) ?? null;

        drag(top, top);

        expect(titles()).toEqual(['Maria Papadopoulou']);
    });
});

describe('the document listener', () => {
    it('hears Escape pressed with focus outside the frame', () => {
        openSingle();

        key(document.body, 'Escape');

        expect(dialogs()).toHaveLength(0);
    });
});

/*
 * The modal's shapes. jsdom lays nothing out, so the classes are what is read here and the
 * geometry is proven at 390 pixels in the browser suite: the default is the centred frame;
 * filling is that frame at or above the breakpoint and the whole viewport below it, the footer
 * still pinned and the body still the one scroller; the drawer is pinned to the start edge at the
 * viewport's full height, and closes as any modal does.
 */
describe('the shapes', () => {
    const classesOf = (element: Element | null) => [...(element?.classList ?? [])];

    function Shaped({ variant }: { variant: 'filling' | 'drawer' }) {
        const [open, setOpen] = useState(true);

        return (
            <Modal open={open} title="Shaped" variant={variant} onClose={() => setOpen(false)} footer={variant === 'filling' ? <Button className="close">Close</Button> : undefined}>
                <p className="long">A long audit trail.</p>
            </Modal>
        );
    }

    it('keeps the centred frame as the default, bounded to the viewport at every width', () => {
        openSingle();

        expect(classesOf(frame())).toEqual(expect.arrayContaining(['max-h-[85vh]', 'max-w-lg', 'rounded-xl']));
        expect(classesOf(frame()).some((name) => name.startsWith('max-desktop:'))).toBe(false);
        expect(classesOf(backdrop())).toEqual(expect.arrayContaining(['items-center', 'justify-center', 'p-4']));
    });

    it('fills the viewport below the breakpoint when filling, the centred frame above it, the footer pinned outside the scrolling body', () => {
        view.render(<Shaped variant="filling" />);

        expect(classesOf(frame())).toEqual(
            expect.arrayContaining(['admin-modal__frame--filling', 'max-h-[85vh]', 'max-w-lg', 'rounded-xl', 'max-desktop:h-full', 'max-desktop:max-h-none', 'max-desktop:max-w-none', 'max-desktop:rounded-none', 'max-desktop:border-0']),
        );
        expect(classesOf(backdrop())).toEqual(expect.arrayContaining(['items-center', 'justify-center', 'p-4', 'max-desktop:p-0']));
        expect(document.querySelector('.admin-modal__body')?.contains(document.querySelector('.long'))).toBe(true);
        expect(document.querySelector('.admin-modal__footer')?.contains(document.querySelector('.close'))).toBe(true);
    });

    it('keeps a consumer\'s width above the breakpoint and still fills below it', () => {
        view.render(
            <Modal open title="Wide" variant="filling" className="max-w-2xl" onClose={() => {}}>
                <p />
            </Modal>,
        );

        expect(classesOf(frame())).toContain('max-w-2xl');
        expect(classesOf(frame())).not.toContain('max-w-lg');
        expect(classesOf(frame())).toContain('max-desktop:max-w-none');
    });

    it('opens as a drawer pinned to the start edge at the viewport\'s full height, a dialog titled as any modal is', () => {
        view.render(<Shaped variant="drawer" />);

        expect(classesOf(frame())).toEqual(expect.arrayContaining(['admin-modal__frame--drawer', 'h-full', 'max-w-72', 'rounded-none']));
        expect(classesOf(frame()).some((name) => name.startsWith('max-h-'))).toBe(false);
        expect(classesOf(backdrop())).toEqual(expect.arrayContaining(['items-stretch', 'justify-start']));
        expect(classesOf(backdrop())).not.toContain('p-4');
        expect(dialogs()).toHaveLength(1);
        expect(dialogs()[0]?.getAttribute('aria-modal')).toBe('true');
        expect(document.querySelector('.admin-modal__title')?.textContent).toBe('Shaped');
    });

    it('closes the drawer on Escape and on a press both down and up on the backdrop, as a modal does', () => {
        view.render(<Shaped variant="drawer" />);
        key(document.body, 'Escape');
        expect(dialogs()).toHaveLength(0);

        view.render(<Shaped key="again" variant="drawer" />);
        drag(backdrop(), backdrop());
        expect(dialogs()).toHaveLength(0);
    });
});
