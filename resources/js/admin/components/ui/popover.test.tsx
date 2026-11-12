import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { drag, key, mount, press, settle, type Mounted } from './harness';
import { Modal } from './modal';
import { Popover, PopoverContent, PopoverTrigger } from './popover';

/*
 * The popover every popover control opens, and one layer per press. Placement is the library's
 * arithmetic and is not proven here; what is proven is this. The content is portaled out of the
 * modal's scrolling body and placed in viewport coordinates, so no body clips it; it is at least
 * the trigger's width and no taller than the room on its side, through the library's variables.
 * Inside a modal one Escape peels the popover and the next the modal; a press on the backdrop or
 * inside the frame with the popover up closes the popover alone; and the same holds with a modal
 * stacked on a modal, the modal's nesting cases.
 *
 * Deterministic by construction: no geometry mocks and no clock. The one wait is the harness's
 * single task, for what the library defers to the next one: its outside-press listener, added a
 * task after the popover opens, and focus handed back once a layer has unmounted.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function Picker({ onOpenChange }: { onOpenChange?: (open: boolean) => void }) {
    const [open, setOpen] = useState(false);

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                onOpenChange?.(next);
                setOpen(next);
            }}
        >
            <PopoverTrigger className="trigger">Pick</PopoverTrigger>
            <PopoverContent className="surface">
                <button type="button" className="inside-surface">
                    Choose
                </button>
            </PopoverContent>
        </Popover>
    );
}

function InModal({ onClose }: { onClose: () => void }) {
    return (
        <Modal open title="New booking" onClose={onClose}>
            <p className="body-text">Pick a date.</p>
            <Picker />
        </Modal>
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
                <Picker />
            </Modal>
        </Modal>
    );
}

const trigger = () => document.querySelector<HTMLElement>('.trigger');
const surface = () => document.querySelector<HTMLElement>('.surface');
const dialogs = () => [...document.querySelectorAll('.admin-modal__frame')];
const backdrop = () => [...document.querySelectorAll('.admin-modal__backdrop')].at(-1) ?? null;
const titles = () => [...document.querySelectorAll('.admin-modal__title')].map((title) => title.textContent);

describe('the popover', () => {
    it('opens from its trigger, expanded, and closes on a second press of it', () => {
        view.render(<Picker />);
        expect(surface()).toBeNull();
        expect(trigger()?.getAttribute('aria-expanded')).toBe('false');

        press(trigger());
        expect(surface()?.classList.contains('admin-popover')).toBe(true);
        expect(trigger()?.getAttribute('aria-expanded')).toBe('true');

        press(trigger());
        expect(surface()).toBeNull();
    });

    it('is portaled out of a modal body into viewport coordinates, so the body scrolling cannot clip it', () => {
        view.render(<InModal onClose={() => {}} />);
        press(trigger());

        expect(document.querySelector('.admin-modal__body')?.contains(surface())).toBe(false);
        expect(surface()?.parentElement?.style.position).toBe('fixed');
    });

    it('is at least the trigger wide and no taller than the room on its side, through the library variables', () => {
        view.render(<Picker />);
        press(trigger());

        expect(surface()?.classList.contains('min-w-(--radix-popover-trigger-width)')).toBe(true);
        expect(surface()?.classList.contains('max-h-(--radix-popover-content-available-height)')).toBe(true);
        expect(surface()?.classList.contains('overflow-y-auto')).toBe(true);
    });

    it('closes on Escape and hands focus back to the trigger', async () => {
        view.render(<Picker />);
        trigger()?.focus();
        press(trigger());

        key(document.querySelector('.inside-surface'), 'Escape');
        await settle();

        expect(surface()).toBeNull();
        expect(document.activeElement).toBe(trigger());
    });

    it('ignores an Escape that is composing text', () => {
        view.render(<Picker />);
        press(trigger());

        key(document.querySelector('.inside-surface'), 'Escape', { isComposing: true });

        expect(surface()).not.toBeNull();
    });

    it('closes on a press outside and stays open on a press inside it', async () => {
        const onOpenChange = vi.fn();
        view.render(
            <>
                <Picker onOpenChange={onOpenChange} />
                <p className="elsewhere">Elsewhere</p>
            </>,
        );
        press(trigger());
        await settle();

        drag(document.querySelector('.inside-surface'), document.querySelector('.inside-surface'));
        expect(surface()).not.toBeNull();

        drag(document.querySelector('.elsewhere'), document.querySelector('.elsewhere'));
        expect(surface()).toBeNull();
        expect(onOpenChange).toHaveBeenLastCalledWith(false);
    });
});

describe('a popover inside a modal', () => {
    it('peels one layer per Escape: the popover, then the modal', () => {
        const onClose = vi.fn();
        view.render(<InModal onClose={onClose} />);
        press(trigger());

        key(document.querySelector('.inside-surface'), 'Escape');
        expect(surface()).toBeNull();
        expect(onClose).not.toHaveBeenCalled();

        key(trigger(), 'Escape');
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('closes the popover alone on a backdrop press, and the modal on the next one', async () => {
        const onClose = vi.fn();
        view.render(<InModal onClose={onClose} />);
        press(trigger());
        await settle();

        drag(backdrop(), backdrop());
        expect(surface()).toBeNull();
        expect(onClose).not.toHaveBeenCalled();

        drag(backdrop(), backdrop());
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('closes the popover alone on a press inside the frame', async () => {
        const onClose = vi.fn();
        view.render(<InModal onClose={onClose} />);
        press(trigger());
        await settle();

        drag(document.querySelector('.body-text'), document.querySelector('.body-text'));

        expect(surface()).toBeNull();
        expect(dialogs()).toHaveLength(1);
        expect(onClose).not.toHaveBeenCalled();
    });
});

describe('a popover inside the top of two stacked modals', () => {
    function openAll(): void {
        view.render(<Stacked />);
        press(document.querySelector('.open-inner'));
        press(trigger());
        expect(dialogs()).toHaveLength(2);
    }

    it('peels one layer per Escape: the popover, the top modal, then the other', () => {
        openAll();

        key(document.querySelector('.inside-surface'), 'Escape');
        expect(surface()).toBeNull();
        expect(dialogs()).toHaveLength(2);

        key(trigger(), 'Escape');
        expect(titles()).toEqual(['Maria Papadopoulou']);

        key(document.querySelector('.open-inner'), 'Escape');
        expect(dialogs()).toHaveLength(0);
    });

    it('closes the popover alone on a backdrop press, then the top modal, never the one under it', async () => {
        openAll();
        await settle();

        drag(backdrop(), backdrop());
        expect(surface()).toBeNull();
        expect(dialogs()).toHaveLength(2);

        drag(backdrop(), backdrop());
        expect(titles()).toEqual(['Maria Papadopoulou']);
    });
});
