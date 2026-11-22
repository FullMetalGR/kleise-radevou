import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Button } from './button';
import { drag, key, mount, press, settle, type Mounted } from './harness';
import { FORCED_EDGE } from './input';
import { Modal } from './modal';
import { keyedShell } from '@/testing/keyed';

/*
 * The modal, and Escape across stacked dialogs. The modal is a backdrop and a frame with the
 * dialog role, aria-modal and a title; an optional footer pins its actions outside the scrolling
 * body; focus moves into the frame on open and back to the opener on close; Escape closes, and so
 * does the round close control, which comes last in the frame so focus never lands on it first; a
 * backdrop press closes only when it both starts and ends on the backdrop; the page behind is
 * hidden from assistive technology and Tab never leaves the frame. Stacked, a parent
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
const classesOf = (element: Element | null) => [...(element?.classList ?? [])];

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

    it('lets the footer\'s actions wrap below the breakpoint, every line still ending at the frame\'s end edge, so a row of long labels never runs out of the start edge where no scroll reaches it', () => {
        openSingle();

        const footer = [...(document.querySelector('.admin-modal__footer')?.classList ?? [])];

        expect(footer).toEqual(expect.arrayContaining(['flex', 'justify-end', 'gap-2', 'max-desktop:flex-wrap']));
        expect(footer).not.toContain('flex-wrap');
    });

    it('keeps Tab inside the frame, wrapping at both ends', () => {
        openSingle();

        const reason = document.querySelector<HTMLElement>('.reason');
        const last = document.querySelector<HTMLElement>('.admin-modal__close');

        last?.focus();
        key(last, 'Tab');
        expect(frame()?.contains(document.activeElement)).toBe(true);
        expect(document.activeElement).not.toBe(last);

        reason?.focus();
        key(reason, 'Tab', { shiftKey: true });
        key(document.activeElement, 'Tab', { shiftKey: true });
        expect(frame()?.contains(document.activeElement)).toBe(true);
    });

    it('hides the page behind an open dialog from assistive technology and turns Tab round inside the frame, from the close control to the first field and back', () => {
        view.render(
            <>
                <main className="behind">
                    <button type="button" className="behind-button">
                        Bookings
                    </button>
                </main>
                <Single />
            </>,
        );
        press(view.host.querySelector('.opener'));

        const close = document.querySelector<HTMLElement>('.admin-modal__close');
        const reason = document.querySelector<HTMLElement>('.reason');

        expect(document.querySelector('.behind')?.closest('[aria-hidden="true"]')).not.toBeNull();
        expect(frame()?.closest('[aria-hidden="true"]')).toBeNull();

        close?.focus();
        key(close, 'Tab');
        expect(document.activeElement).toBe(reason);

        key(reason, 'Tab', { shiftKey: true });
        expect(document.activeElement).toBe(close);
    });

    it('closes from a round, named close control set after the footer, so focus still lands first in the body', async () => {
        const onClose = vi.fn();
        const opener = openSingle(onClose);
        const close = document.querySelector('.admin-modal__close');

        expect(document.activeElement).toBe(document.querySelector('.reason'));
        expect(close?.tagName).toBe('BUTTON');
        expect(close?.textContent).toBe(keyedShell().text('kit.modal.close'));
        expect(classesOf(close)).toEqual(expect.arrayContaining(['admin-button--icon-only', 'rounded-full', 'size-10', 'absolute']));
        expect(close?.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
        expect(frame()?.lastElementChild).toBe(close);

        press(close);
        await settle();

        expect(onClose).toHaveBeenCalledOnce();
        expect(dialogs()).toHaveLength(0);
        expect(document.activeElement).toBe(opener);
    });

    it('draws the close control at 40 pixels and takes a press 2 pixels past each of its sides, a touch area of 44', () => {
        openSingle();

        const close = classesOf(document.querySelector('.admin-modal__close'));

        expect(close).toEqual(expect.arrayContaining(['absolute', 'size-10', 'after:absolute', 'after:-inset-0.5']));
        expect(close.some((name) => name.startsWith('size-') && name !== 'size-10')).toBe(false);
    });

    it('disables the close control while its owner is busy, as the footer\'s buttons are, and leaves it live otherwise', () => {
        const onClose = vi.fn();
        const close = () => document.querySelector<HTMLButtonElement>('.admin-modal__close');

        view.render(
            <Modal open title="Saving the week" busy onClose={onClose}>
                <p />
            </Modal>,
        );

        expect(close()?.disabled).toBe(true);
        press(close());
        expect(onClose).not.toHaveBeenCalled();

        view.render(
            <Modal open title="Saving the week" busy={false} onClose={onClose}>
                <p />
            </Modal>,
        );

        expect(close()?.disabled).toBe(false);
        press(close());
        expect(onClose).toHaveBeenCalledOnce();
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
 * geometry is proven in the browser suite at 390 pixels and across the breakpoint: the default
 * is the centred frame, a card on the card's fill no wider than 780 pixels with the shell's
 * radius and the dialog's shadow, which below the breakpoint is a sheet at the bottom edge, the
 * screen's width, rounded at the top alone and never taller than 94 percent of the screen;
 * filling is that card at or above the breakpoint and the whole viewport below it, the footer
 * still pinned and the body still the one scroller; full is the whole viewport at every width,
 * the footer pinned and the body the one scroller; the drawer is pinned to the start edge at the
 * viewport's full height, and closes as any modal does. Every backdrop is the scrim, blurred.
 * A frame changes shape by its classes alone, so crossing the breakpoint never remounts it, and
 * it rises into place only where motion is welcome. Beside the title, an aside shares its row.
 */
describe('the shapes', () => {
    function Shaped({ variant }: { variant: 'filling' | 'full' | 'drawer' }) {
        const [open, setOpen] = useState(true);

        return (
            <Modal open={open} title="Shaped" variant={variant} onClose={() => setOpen(false)} footer={variant === 'drawer' ? undefined : <Button className="close">Close</Button>}>
                <p className="long">A long audit trail.</p>
            </Modal>
        );
    }

    const CARD = ['bg-card', 'text-card-foreground', 'max-h-[calc(100dvh-3rem)]', 'max-w-[780px]', 'rounded-shell', 'shadow-modal'];

    it('draws the centred frame by default as a card on the card\'s fill, and below the breakpoint as a sheet at the bottom edge, over a blurred scrim', () => {
        openSingle();

        expect(classesOf(frame())).toEqual(
            expect.arrayContaining([
                'admin-modal__frame--centred',
                ...CARD,
                'max-desktop:self-end',
                'max-desktop:max-w-none',
                'max-desktop:max-h-[94dvh]',
                'max-desktop:rounded-t-[1.75rem]',
                'max-desktop:rounded-b-none',
            ]),
        );
        expect(classesOf(frame())).not.toContain('bg-background');
        expect(classesOf(frame()).some((name) => name.startsWith('max-desktop:h-'))).toBe(false);
        expect(classesOf(backdrop())).toEqual(expect.arrayContaining(['bg-scrim', 'backdrop-blur-[3px]', 'items-center', 'justify-center', 'p-6', 'max-desktop:p-0']));
    });

    it('keeps a consumer\'s cap on the centred card, and the sheet still the screen\'s width', () => {
        view.render(
            <Modal open title="Narrow" className="max-w-lg" onClose={() => {}}>
                <p />
            </Modal>,
        );

        expect(classesOf(frame())).toContain('max-w-lg');
        expect(classesOf(frame())).not.toContain('max-w-[780px]');
        expect(classesOf(frame())).toContain('max-desktop:max-w-none');
    });

    it('rises into place under motion-safe alone, a sheet from further down, the scrim fading in', () => {
        openSingle();

        const moving = (element: Element | null) => classesOf(element).filter((name) => /starting:|transition|duration-|ease-/.test(name));

        expect(classesOf(frame())).toEqual(
            expect.arrayContaining([
                'motion-safe:duration-400',
                'motion-safe:ease-soft',
                'motion-safe:starting:translate-y-3.5',
                'motion-safe:starting:scale-[0.985]',
                'max-desktop:motion-safe:starting:translate-y-10',
            ]),
        );
        expect(classesOf(backdrop())).toEqual(expect.arrayContaining(['motion-safe:transition-opacity', 'motion-safe:starting:opacity-0']));
        expect([...moving(frame()), ...moving(backdrop())].every((name) => name.startsWith('motion-safe:') || name.startsWith('max-desktop:motion-safe:'))).toBe(true);
    });

    it('sets the title at the dialog\'s size, a step down below the breakpoint, and a prompt\'s a step down at every width', () => {
        openSingle();

        expect(classesOf(document.querySelector('.admin-modal__title'))).toEqual(expect.arrayContaining(['text-dialog-title', 'max-desktop:text-section-title']));

        view.render(
            <Modal open title="Remove this note?" prompt onClose={() => {}}>
                <p />
            </Modal>,
        );

        expect(classesOf(document.querySelector('.admin-modal__title'))).toContain('text-section-title');
        expect(classesOf(document.querySelector('.admin-modal__title'))).not.toContain('text-dialog-title');
        expect(classesOf(document.querySelector('.admin-modal__body'))).toContain('text-body');
    });

    it('lets a title of one long unbroken word break anywhere, so it wraps short of the close control instead of running under it', () => {
        openSingle();

        expect(classesOf(document.querySelector('.admin-modal__title'))).toContain('wrap-anywhere');

        view.render(
            <Modal open title="With aside" onClose={() => {}} aside={<span className="aside" />}>
                <p />
            </Modal>,
        );

        expect(classesOf(document.querySelector('.admin-modal__title'))).toContain('wrap-anywhere');
    });

    it.each(['centred', 'filling'] as const)('gives the %s frame\'s title, body and footer the phone\'s narrower room below the breakpoint, the close control moving in with them and still clear of the title', (variant) => {
        view.render(
            <Modal open title="Shaped" variant={variant} onClose={() => {}} footer={<Button className="close">Close</Button>}>
                <p />
            </Modal>,
        );

        expect(classesOf(document.querySelector('.admin-modal__title'))).toEqual(
            expect.arrayContaining(['px-6.5', 'pt-5.5', 'pb-3', 'pr-18', 'max-desktop:px-4.5', 'max-desktop:pt-4.5', 'max-desktop:pb-2', 'max-desktop:pr-16']),
        );
        expect(classesOf(document.querySelector('.admin-modal__body'))).toEqual(expect.arrayContaining(['px-6.5', 'pt-1', 'pb-5.5', 'max-desktop:px-4.5', 'max-desktop:pb-4.5']));
        expect(classesOf(document.querySelector('.admin-modal__footer'))).toEqual(expect.arrayContaining(['px-6.5', 'py-3.5', 'max-desktop:px-4.5', 'max-desktop:py-3']));
        expect(classesOf(document.querySelector('.admin-modal__close'))).toEqual(expect.arrayContaining(['top-4.5', 'right-5', 'max-desktop:top-3', 'max-desktop:right-3']));
    });

    it('keeps the end of a row shared with an aside clear of the close control at both widths, and the drawer\'s room its own', () => {
        view.render(
            <Modal open title="With aside" onClose={() => {}} aside={<span className="aside" />}>
                <p />
            </Modal>,
        );

        expect(classesOf(document.querySelector('.admin-modal__head'))).toEqual(expect.arrayContaining(['pr-18', 'max-desktop:pr-16']));
        expect(classesOf(document.querySelector('.admin-modal__head'))).not.toContain('pr-3');

        view.render(<Shaped key="drawer" variant="drawer" />);

        expect(classesOf(document.querySelector('.admin-modal__title'))).toContain('sr-only');
        expect(classesOf(document.querySelector('.admin-modal__body'))).toEqual(expect.arrayContaining(['px-4', 'pt-4']));
        expect([...classesOf(document.querySelector('.admin-modal__title')), ...classesOf(document.querySelector('.admin-modal__body'))].some((name) => name.startsWith('max-desktop:'))).toBe(false);
    });

    it('fills the viewport below the breakpoint when filling, the card above it, the footer pinned outside the scrolling body', () => {
        view.render(<Shaped variant="filling" />);

        expect(classesOf(frame())).toEqual(
            expect.arrayContaining(['admin-modal__frame--filling', ...CARD, 'max-desktop:h-full', 'max-desktop:max-h-none', 'max-desktop:max-w-none', 'max-desktop:rounded-none', 'max-desktop:shadow-none']),
        );
        expect(classesOf(frame())).not.toContain('max-desktop:self-end');
        expect(classesOf(backdrop())).toEqual(expect.arrayContaining(['bg-scrim', 'items-center', 'justify-center', 'p-6', 'max-desktop:p-0']));
        expect(document.querySelector('.admin-modal__body')?.contains(document.querySelector('.long'))).toBe(true);
        expect(document.querySelector('.admin-modal__footer')?.contains(document.querySelector('.close'))).toBe(true);
        expect(document.querySelector('.admin-modal__close')).not.toBeNull();
    });

    it('keeps a consumer\'s width above the breakpoint and still fills below it', () => {
        view.render(
            <Modal open title="Wide" variant="filling" className="max-w-2xl" onClose={() => {}}>
                <p />
            </Modal>,
        );

        expect(classesOf(frame())).toContain('max-w-2xl');
        expect(classesOf(frame())).not.toContain('max-w-[780px]');
        expect(classesOf(frame())).toContain('max-desktop:max-w-none');
    });

    it('fills the viewport at every width when full, on the card\'s fill with no breakpoint variant, edge or shadow, the footer pinned outside the scrolling body', () => {
        view.render(<Shaped variant="full" />);

        expect(classesOf(frame())).toEqual(expect.arrayContaining(['admin-modal__frame--full', 'bg-card', 'h-full', 'max-h-none', 'max-w-none', 'rounded-none']));
        expect(classesOf(frame()).some((name) => name.startsWith('max-desktop:') || name.startsWith('desktop:'))).toBe(false);
        expect(classesOf(frame()).some((name) => name === 'border' || name.startsWith('shadow-'))).toBe(false);
        expect(classesOf(backdrop())).toEqual(expect.arrayContaining(['items-stretch', 'justify-stretch']));
        expect(classesOf(backdrop())).not.toContain('p-6');
        expect(document.querySelector('.admin-modal__body')?.contains(document.querySelector('.long'))).toBe(true);
        expect(document.querySelector('.admin-modal__footer')?.contains(document.querySelector('.close'))).toBe(true);
        expect(document.querySelector('.admin-modal__close')).toBeNull();
    });

    it('lays the full frame\'s title row, its body\'s content and its footer\'s content in one centred column at most 62.5rem wide, the phone\'s sides below the breakpoint, while the frame, the scroll and the footer\'s rule span the window; the body\'s content is the query container', () => {
        view.render(<Shaped variant="full" />);

        const column = ['px-[max(1.625rem,50%_-_31.25rem)]', 'max-desktop:px-4.5'];
        const sides = (element: Element | null) => classesOf(element).filter((name) => /^(max-desktop:)?p[xlr]-/.test(name));

        expect(classesOf(document.querySelector('.admin-modal__title'))).toEqual(expect.arrayContaining(['pt-5.5', 'pb-3', 'max-desktop:pt-4.5', 'max-desktop:pb-2']));
        expect(classesOf(document.querySelector('.admin-modal__body'))).toEqual(expect.arrayContaining(['overflow-y-auto', 'pt-1', 'pb-5.5', 'max-desktop:pb-4.5']));
        expect(classesOf(document.querySelector('.admin-modal__footer'))).toEqual(expect.arrayContaining(['border-t', 'py-3.5', 'max-desktop:py-3']));

        for (const part of ['.admin-modal__title', '.admin-modal__body', '.admin-modal__footer']) {
            expect(sides(document.querySelector(part))).toEqual(column);
        }

        const content = document.querySelector('.admin-modal__body > .admin-modal__column');
        expect(classesOf(content)).toEqual(['admin-modal__column', '@container']);
        expect(content?.contains(document.querySelector('.long'))).toBe(true);
        expect(classesOf(frame())).not.toContain('@container');

        view.render(
            <Modal open title="With aside" variant="full" onClose={() => {}} aside={<span className="aside" />}>
                <p />
            </Modal>,
        );

        expect(sides(document.querySelector('.admin-modal__head'))).toEqual(column);
        expect(sides(document.querySelector('.admin-modal__title'))).toEqual([]);
    });

    it.each(['centred', 'filling'] as const)('keeps the %s frame\'s body content where it was, with no column of its own', (variant) => {
        view.render(
            <Modal open title="Shaped" variant={variant} onClose={() => {}}>
                <p className="long" />
            </Modal>,
        );

        expect(document.querySelector('.admin-modal__column')).toBeNull();
        expect(document.querySelector('.long')?.parentElement).toBe(document.querySelector('.admin-modal__body'));
    });

    it('moves the title\'s room onto the row it shares with an aside, and sets the aside in a box one of the title\'s lines high at the row\'s top, so it centres on the title\'s first line at both widths, the title keeping its type alone', () => {
        const room = ['px-6.5', 'pt-5.5', 'pb-3', 'max-desktop:px-4.5', 'max-desktop:pt-4.5', 'max-desktop:pb-2'];
        const padding = (element: Element | null) => classesOf(element).filter((name) => /^(max-desktop:)?p[trblxy]?-/.test(name));

        view.render(
            <Modal open title="With aside" variant="filling" onClose={() => {}} aside={<span className="aside" />}>
                <p />
            </Modal>,
        );

        expect(classesOf(document.querySelector('.admin-modal__head'))).toEqual(expect.arrayContaining(['flex', 'items-start', ...room, 'pr-18', 'max-desktop:pr-16']));
        expect(padding(document.querySelector('.admin-modal__title'))).toEqual([]);
        expect(classesOf(document.querySelector('.admin-modal__title'))).toEqual(expect.arrayContaining(['text-dialog-title', 'max-desktop:text-section-title']));

        const box = document.querySelector('.aside')?.parentElement ?? null;
        expect(box?.parentElement).toBe(document.querySelector('.admin-modal__head'));
        expect(classesOf(box)).toEqual(
            expect.arrayContaining(['flex', 'items-center', 'shrink-0', 'h-[calc(var(--text-dialog-title)*var(--text-dialog-title--line-height))]', 'max-desktop:h-[calc(var(--text-section-title)*var(--text-section-title--line-height))]']),
        );
        expect(classesOf(box).some((name) => name.startsWith('text-') || name.startsWith('max-desktop:text-'))).toBe(false);
    });

    it('sets an aside on the title\'s row after it, the title still what labels the dialog, and keeps the title on its own row without one', () => {
        view.render(
            <Modal open title="With aside" onClose={() => {}} aside={<button type="button" className="aside">Close</button>}>
                <p />
            </Modal>,
        );

        const head = document.querySelector('.admin-modal__head');
        const title = document.getElementById(dialogs()[0]?.getAttribute('aria-labelledby') ?? '');
        expect(title?.textContent).toBe('With aside');
        expect(head?.contains(title)).toBe(true);
        expect(title?.compareDocumentPosition(document.querySelector('.aside') as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(head?.contains(document.querySelector('.aside'))).toBe(true);

        view.render(<Shaped key="plain" variant="drawer" />);
        expect(document.querySelector('.admin-modal__head')).toBeNull();
        expect(document.querySelector('.admin-modal__title')?.parentElement).toBe(frame());
    });

    it('opens as a drawer pinned to the start edge at the viewport\'s full height, rounded at its far side, a dialog titled for a screen reader as any modal is', () => {
        view.render(<Shaped variant="drawer" />);

        expect(classesOf(frame())).toEqual(expect.arrayContaining(['admin-modal__frame--drawer', 'bg-card', 'text-card-foreground', 'h-dvh', 'w-[min(88vw,340px)]', 'rounded-r-shell', 'shadow-drawer']));
        expect(classesOf(frame())).not.toEqual(expect.arrayContaining(['w-full']));
        expect(classesOf(frame())).not.toContain('max-w-72');
        expect(classesOf(frame())).not.toContain('rounded-none');
        expect(classesOf(frame()).some((name) => name.startsWith('max-h-'))).toBe(false);
        expect(classesOf(frame())).not.toContain('bg-background');
        expect(classesOf(backdrop())).toEqual(expect.arrayContaining(['bg-scrim', 'items-stretch', 'justify-start']));
        expect(classesOf(backdrop())).not.toContain('p-6');
        expect(document.querySelector('.admin-modal__close')).toBeNull();
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

    it('draws a real line round the centred and filling card and the drawer in forced colours, where their shadow and the scrim are dropped, and none round the frame that fills the window', () => {
        openSingle();
        expect(classesOf(frame())).toContain(FORCED_EDGE);

        for (const variant of ['filling', 'drawer'] as const) {
            view.render(<Shaped key={variant} variant={variant} />);
            expect(classesOf(frame())).toContain(FORCED_EDGE);
        }

        view.render(<Shaped key="full" variant="full" />);
        expect(classesOf(frame()).some((name) => name.startsWith('forced-colors:'))).toBe(false);
    });
});
