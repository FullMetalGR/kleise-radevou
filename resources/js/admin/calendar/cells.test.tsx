import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { key, mount, type Mounted, press, settle } from '@/components/ui/harness';
import { statusLabel } from '@/components/ui/status-chip';
import { hueOf } from '@/lib/hue';
import { keyedCalendar, keyedShell, WithCalendar } from '@/testing/keyed';
import { CappedChips, MORE } from './CappedChips';
import { BookingChip, CHIP_NAME, CHIP_OUTLINES, ERASED_CUSTOMER, INSIDE, NOTED } from './Chip';
import { DateMenu, NEW_BOOKING, NEW_CLOSURE, OPEN_DAY } from './DateMenu';
import { type Chip, type Colouring, fillOf, statusHueOf } from './model';

/*
 * The parts a day's cell is made of, each on its own, as any grid holding them would render them:
 * the booking chip with its marks, a cell's chips under a cap with the popover listing them all,
 * and a day's date menu. Every word reads through the keyed calendar section they sit in.
 */

const shell = keyedShell();

const cal = keyedCalendar();

const CLOCK = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const STAFF: Colouring = { by: 'staff', palette: true, categories: [], own: [] };

function chip(overrides: Partial<Chip> = {}): Chip {
    return { uuid: 'a', start: '2026-11-03T10:00:00', customer: 'Ada North', status: 'confirmed', staffId: 2, category: null, badge: null, noted: false, inside: false, movable: false, segments: [0], ...overrides };
}

let view: Mounted;

function show(node: ReactNode) {
    view.render(<WithCalendar>{node}</WithCalendar>);
}

/** What a screen reader reads of an element: its text without the parts kept from readers. */
function heard(element: Element | null | undefined): string {
    return element === null || element === undefined ? '' : [...element.childNodes].map((node) => (node instanceof Element ? (node.getAttribute('aria-hidden') === 'true' ? '' : heard(node)) : (node.textContent ?? ''))).join('');
}

/** A palette hex as the DOM spells a colour it parsed. */
function rgb(hex: string): string {
    return `rgb(${[1, 3, 5].map((at) => parseInt(hex.slice(at, at + 2), 16)).join(', ')})`;
}

/** A chip's name as the keyed section fills it. */
function nameOf(time: string, customer: string, status: string): string {
    return cal.text(CHIP_NAME, { time, customer, status: statusLabel(status, shell) });
}

/**
 * A pointer going down on an element, a mouse's, a finger's or a pen's, at a point of the window:
 * the main button with no key held, unless the caller says another.
 */
function pointerDown(element: Element | null, pointerType: 'mouse' | 'touch' | 'pen', at: { x: number; y: number } = { x: 0, y: 0 }, held: { button?: number; ctrlKey?: boolean } = {}): void {
    if (element === null) {
        throw new Error('Nothing to press.');
    }

    act(() => {
        element.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, button: 0, ctrlKey: false, ...held, pointerType, clientX: at.x, clientY: at.y }));
    });
}

/**
 * The click a pointer makes as its press ends on an element: it counts the press, as a browser's
 * does. The harness's own press is the click no pointer made, which counts none.
 */
function pointerClick(element: Element | null): void {
    if (element === null) {
        throw new Error('Nothing to click.');
    }

    act(() => {
        element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 }));
    });
}

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('a booking chip', () => {
    const chipEl = (uuid = 'a') => view.host.querySelector<HTMLElement>(`.admin-calendar-chip[data-uuid="${uuid}"]`);

    it('shows the start in the tenant format and the customer, and is named by the time, the customer and the status', () => {
        show(<BookingChip chip={chip()} colouring={STAFF} clock={{ ...CLOCK, timeFormat: 'g:i A' }} onOpen={() => undefined} />);

        expect(chipEl()?.tagName).toBe('BUTTON');
        expect(chipEl()?.querySelector('.admin-calendar-chip__time')?.textContent).toBe('10:00 AM');
        expect(chipEl()?.querySelector('.admin-calendar-chip__customer')?.textContent).toBe('Ada North');
        expect(heard(chipEl())).toBe(nameOf('10:00 AM', 'Ada North', 'confirmed'));
        expect(chipEl()?.dataset['status']).toBe('confirmed');
    });

    it('names an erased customer in the admin\'s words, on the chip and to a screen reader', () => {
        show(<BookingChip chip={chip({ customer: null })} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />);

        expect(chipEl()?.querySelector('.admin-calendar-chip__customer')?.textContent).toBe(cal.text(ERASED_CUSTOMER));
        expect(heard(chipEl())).toBe(nameOf('10:00', cal.text(ERASED_CUSTOMER), 'confirmed'));
    });

    it('wears its hue as a block does, the fill mixed toward the surface and a bar of the full hue, Colour by deciding it', () => {
        show(
            <>
                <BookingChip chip={chip()} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
                <BookingChip chip={chip({ uuid: 'b', status: 'awaiting_approval' })} colouring={{ ...STAFF, by: 'status' }} clock={CLOCK} onOpen={() => undefined} />
            </>,
        );

        expect(chipEl()?.style.backgroundColor).toBe(fillOf(hueOf(2, [])));
        expect(chipEl()?.style.borderLeftColor).toBe(rgb(hueOf(2, [])));
        expect(chipEl('b')?.style.backgroundColor).toBe(fillOf(statusHueOf('awaiting_approval')));
        // The bar is four pixels, a left border.
        expect(chipEl()?.className.split(' ').filter((name) => name.startsWith('border-l-'))).toEqual(['border-l-4']);
    });

    it('strikes the customer of a booking cancelled, rejected, missed or expired on the tertiary ink\'s softer fill, its bar still its hue, and strikes nothing else', () => {
        show(
            <>
                <BookingChip chip={chip({ uuid: 'c', status: 'cancelled' })} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
                <BookingChip chip={chip({ uuid: 'j', status: 'rejected' })} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
                <BookingChip chip={chip({ uuid: 'm', status: 'no_show' })} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
                <BookingChip chip={chip({ uuid: 'x', status: 'expired' })} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
                <BookingChip chip={chip()} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
            </>,
        );

        for (const uuid of ['c', 'j', 'm', 'x']) {
            expect(chipEl(uuid)?.classList.contains('admin-calendar-chip--struck')).toBe(true);
            expect(chipEl(uuid)?.querySelector('.admin-calendar-chip__customer')?.classList.contains('line-through')).toBe(true);
            expect(chipEl(uuid)?.style.backgroundColor).toBe('color-mix(in oklab, var(--ink-tertiary) 9%, var(--card))');
            expect(chipEl(uuid)?.style.borderLeftColor).toBe(rgb(hueOf(2, [])));
        }

        expect(chipEl()?.classList.contains('admin-calendar-chip--struck')).toBe(false);
        expect(chipEl()?.querySelector('.admin-calendar-chip__customer')?.classList.contains('line-through')).toBe(false);
    });

    it('outlines a request solid in the amber its block is outlined in, by the class the stylesheet colours, and an approved booking awaiting payment dashed in the theme colour, and no other', () => {
        show(
            <>
                <BookingChip chip={chip({ uuid: 'r', status: 'awaiting_approval', badge: 'awaiting_approval' })} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
                <BookingChip chip={chip({ uuid: 'p', status: 'awaiting_payment', badge: 'awaiting_payment' })} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
                <BookingChip chip={chip()} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />
            </>,
        );

        expect(chipEl('r')?.className.split(' ')).toEqual(expect.arrayContaining(CHIP_OUTLINES.awaiting_approval.split(' ')));
        expect(chipEl('r')?.className.split(' ')).toEqual(expect.arrayContaining(['outline-solid', 'admin-calendar-outline--awaiting_approval']));
        // The attention colour itself is too pale a line on a light ground, so no utility of it is worn.
        expect(chipEl('r')?.classList.contains(['outline', 'attention'].join('-'))).toBe(false);
        expect(chipEl('p')?.className.split(' ')).toEqual(expect.arrayContaining(CHIP_OUTLINES.awaiting_payment.split(' ')));
        expect(chipEl('p')?.className.split(' ')).toEqual(expect.arrayContaining(['outline-dashed', 'outline-primary']));
        // Off the raw palette: no outline names a fixed hue any more.
        expect(Object.values(CHIP_OUTLINES).join(' ')).not.toMatch(/outline-(amber|sky)-/);
        expect(chipEl()?.classList.contains('admin-calendar-chip--outlined')).toBe(false);
        expect(heard(chipEl('r'))).toBe(nameOf('10:00', 'Ada North', 'awaiting_approval'));
    });

    it('marks a note and a closure holding the booking\'s time, no word of either on the chip, each named and titled', () => {
        show(<BookingChip chip={chip({ noted: true, inside: true })} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />);

        const noted = chipEl()?.querySelector('.admin-calendar-chip__noted');
        const inside = chipEl()?.querySelector('.admin-calendar-chip__inside');
        expect([noted?.getAttribute('role'), noted?.getAttribute('aria-label'), noted?.getAttribute('title')]).toEqual(['img', cal.text(NOTED), cal.text(NOTED)]);
        expect([inside?.getAttribute('role'), inside?.getAttribute('aria-label'), inside?.getAttribute('title')]).toEqual(['img', cal.text(INSIDE), cal.text(INSIDE)]);
        expect(noted?.textContent).toBe('');
        expect(inside?.textContent).toBe('!');
        expect(chipEl()?.textContent).not.toContain(cal.text(NOTED));
    });

    it('hands its booking to the caller on a press, and passes on what else the caller hands a button', () => {
        const onOpen = vi.fn();
        const pointed = vi.fn();
        show(<BookingChip chip={chip()} colouring={STAFF} clock={CLOCK} onOpen={onOpen} className="extra" data-row="2" onPointerDown={pointed} />);

        press(chipEl());
        chipEl()?.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));

        expect(onOpen.mock.calls).toEqual([['a']]);
        expect(chipEl()?.classList.contains('extra')).toBe(true);
        expect(chipEl()?.dataset['row']).toBe('2');
        expect(pointed).toHaveBeenCalled();
    });
});

describe('a capped cell', () => {
    const CHIPS = ['a', 'b', 'c', 'd'].map((uuid, at) => chip({ uuid, start: `2026-11-03T1${at}:00:00` }));
    const TITLE = 'Tuesday 03/11/2026';
    const more = () => view.host.querySelector<HTMLElement>('.admin-calendar-more');
    const popover = () => document.querySelector<HTMLElement>('.admin-calendar-popover');
    /** The clear layer that takes a press outside the popover. */
    const layer = () => document.querySelector<HTMLElement>('.admin-calendar-scrim');

    async function opened(onOpen: (uuid: string) => void = () => undefined) {
        show(<CappedChips chips={CHIPS} cap={2} title={TITLE} colouring={STAFF} clock={CLOCK} onOpen={onOpen} />);
        press(more());
        await settle();
    }

    it('shows the chips up to the cap in the order handed, then a line saying how many more', () => {
        show(<CappedChips chips={CHIPS} cap={2} title={TITLE} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />);

        expect([...view.host.querySelectorAll<HTMLElement>('.admin-calendar-chip')].map((el) => el.dataset['uuid'])).toEqual(['a', 'b']);
        expect(more()?.textContent).toBe(cal.choice(MORE, 2));

        show(<CappedChips chips={CHIPS.slice(0, 2)} cap={2} title={TITLE} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />);

        expect(view.host.querySelectorAll('.admin-calendar-chip')).toHaveLength(2);
        expect(more()).toBeNull();
    });

    it('opens a popover from the line, titled as the caller says, listing every chip of the cell in order', async () => {
        await opened();

        expect(popover()?.getAttribute('aria-label')).toBe(TITLE);
        expect(popover()?.querySelector('.admin-calendar-popover__title')?.textContent).toBe(TITLE);
        expect([...(popover()?.querySelectorAll<HTMLElement>('.admin-calendar-chip') ?? [])].map((el) => el.dataset['uuid'])).toEqual(['a', 'b', 'c', 'd']);
    });

    it('opens a listed booking on a press, the popover closing and the focus back on the line', async () => {
        const onOpen = vi.fn();
        await opened(onOpen);

        press(popover()?.querySelector('.admin-calendar-chip[data-uuid="c"]') ?? null);
        await settle();

        expect(onOpen.mock.calls).toEqual([['c']]);
        expect(popover()).toBeNull();
        expect(layer()).toBeNull();
        expect(document.activeElement).toBe(more());
    });

    it('closes on Escape, the focus back on the line, opening nothing', async () => {
        const onOpen = vi.fn();
        await opened(onOpen);

        key(document.activeElement, 'Escape');
        await settle();

        expect(popover()).toBeNull();
        expect(layer()).toBeNull();
        expect(document.activeElement).toBe(more());
        expect(onOpen).not.toHaveBeenCalled();
    });

    it('lays a clear layer over the page while the popover is up, the document\'s own and one element throughout', async () => {
        show(<CappedChips chips={CHIPS} cap={2} title={TITLE} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />);

        expect(layer()).toBeNull();

        await opened();
        const laid = layer();

        expect(laid?.parentElement).toBe(document.body);

        show(<CappedChips chips={CHIPS} cap={2} title={TITLE} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />);

        expect(layer()).toBe(laid);

        show(<CappedChips chips={CHIPS.slice(0, 2)} cap={2} title={TITLE} colouring={STAFF} clock={CLOCK} onOpen={() => undefined} />);

        expect(layer()).toBeNull();
    });

    it('closes on a press outside it, which the layer takes: the focus back on the line, opening nothing', async () => {
        const onOpen = vi.fn();
        await opened(onOpen);

        pointerDown(layer(), 'mouse');
        press(layer());
        await settle();

        expect(popover()).toBeNull();
        expect(layer()).toBeNull();
        expect(document.activeElement).toBe(more());
        expect(onOpen).not.toHaveBeenCalled();
    });
});

describe('a day\'s date menu', () => {
    const TITLE = 'Tuesday 03/11/2026';
    const control = () => view.host.querySelector<HTMLElement>('.admin-calendar-date');
    const items = () => [...document.querySelectorAll<HTMLElement>('.admin-menu__item')];
    /** How wide the library measured what the open list hangs from: nothing for a point, the control's width for the control. */
    const hung = () => document.querySelector<HTMLElement>('[data-radix-popper-content-wrapper]')?.style.getPropertyValue('--radix-popper-anchor-width');

    async function open() {
        control()?.focus();
        key(control(), 'Enter');
        await settle();
    }

    /**
     * Focus on the control ahead of a press. jsdom, moving focus once the focused element has left
     * the page, blurs the window first, as no browser does, and a menu closes when its window loses
     * focus: the list a press opened would be gone before it was read.
     */
    const rest = () => control()?.focus();

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('is a button named by its name, the title unless the caller says more, around the caller\'s face', () => {
        show(
            <DateMenu title={TITLE} name={`${TITLE}, 3`} onDay={() => undefined} onBook={() => undefined}>
                <span aria-hidden="true">3</span>
            </DateMenu>,
        );

        expect(control()?.tagName).toBe('BUTTON');
        expect(heard(control())).toBe(`${TITLE}, 3`);
        expect(control()?.textContent).toBe(`${TITLE}, 33`);
    });

    it('opens on Enter titled by the day, New booking, New closure and Open day in that order, each handing the press to its handler', async () => {
        const onBook = vi.fn();
        const onClosure = vi.fn();
        const onDay = vi.fn();
        show(<DateMenu title={TITLE} onBook={onBook} onClosure={onClosure} onDay={onDay} />);

        await open();

        const menu = document.querySelector<HTMLElement>('.admin-menu');
        expect(menu?.getAttribute('aria-label')).toBe(TITLE);
        expect(menu?.querySelector('.admin-menu__title')?.textContent).toBe(TITLE);
        expect(items().map((item) => item.textContent)).toEqual([NEW_BOOKING, NEW_CLOSURE, OPEN_DAY].map((name) => cal.text(name)));

        press(document.querySelector('.admin-calendar-date__book'));
        await settle();
        expect(document.activeElement).toBe(control());
        await open();
        press(document.querySelector('.admin-calendar-date__closure'));
        await settle();
        expect(document.activeElement).toBe(control());
        await open();
        press(document.querySelector('.admin-calendar-date__day'));
        await settle();

        expect([onBook.mock.calls.length, onClosure.mock.calls.length, onDay.mock.calls.length]).toEqual([1, 1, 1]);
    });

    it('opens as a mouse goes down on it, wherever on it, the list hung from that point, and the click ending the press picks nothing and leaves it open', async () => {
        const handlers = [vi.fn(), vi.fn(), vi.fn()] as const;
        show(<DateMenu title={TITLE} onBook={handlers[0]} onClosure={handlers[1]} onDay={handlers[2]} />);
        // A control of a cell's size, which nothing lays out here.
        vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
            return this.classList.contains('admin-calendar-date') ? new DOMRect(200, 300, 120, 80) : new DOMRect(0, 0, 0, 0);
        });

        for (const at of [{ x: 204, y: 304 }, { x: 316, y: 376 }, { x: 260, y: 340 }]) {
            rest();
            pointerDown(control(), 'mouse', at);
            await settle();

            expect(items()).toHaveLength(3);
            expect(hung()).toBe('0px');

            pointerClick(control());
            await settle();

            expect(items()).toHaveLength(3);
            expect(handlers.map((handler) => handler.mock.calls.length)).toEqual([0, 0, 0]);

            key(document.activeElement, 'Escape');
            await settle();
            expect(items()).toHaveLength(0);
        }

        // Opened by a key it hangs from the control itself, as any menu does from its trigger.
        await open();
        expect(hung()).toBe('120px');
    });

    it('waits for the tap under a finger or a pen: the pointer going down opens nothing, as a touch scrolling the page starts, and the tap\'s click opens it', async () => {
        show(<DateMenu title={TITLE} onBook={() => undefined} onDay={() => undefined} />);

        for (const pointerType of ['touch', 'pen'] as const) {
            rest();
            pointerDown(control(), pointerType);
            await settle();
            expect(document.querySelector('.admin-menu')).toBeNull();

            pointerClick(control());
            await settle();
            expect(items()).toHaveLength(2);

            // A tap on the control while its menu is up closes it, and its click opens it no second time.
            pointerDown(control(), pointerType);
            await settle();
            pointerClick(control());
            await settle();
            expect(document.querySelector('.admin-menu')).toBeNull();
        }
    });

    it('closes under a mouse press that finds it open, and opens on a click no pointer came before', async () => {
        show(<DateMenu title={TITLE} onBook={() => undefined} onDay={() => undefined} />);

        rest();
        press(control());
        await settle();
        expect(items()).toHaveLength(2);

        pointerDown(control(), 'mouse');
        await settle();
        pointerClick(control());
        await settle();
        expect(document.querySelector('.admin-menu')).toBeNull();
    });

    it('opens on a click no pointer made after a press that opened nothing: the right button, the middle one, the main one with ctrl held', async () => {
        show(<DateMenu title={TITLE} onBook={() => undefined} onDay={() => undefined} />);

        for (const held of [{ button: 2 }, { button: 1 }, { ctrlKey: true }]) {
            rest();
            pointerDown(control(), 'mouse', { x: 0, y: 0 }, held);
            await settle();
            expect(document.querySelector('.admin-menu')).toBeNull();

            press(control());
            await settle();
            expect(items()).toHaveLength(2);

            key(document.activeElement, 'Escape');
            await settle();
            expect(document.querySelector('.admin-menu')).toBeNull();
        }
    });

    it('opens on a click no pointer made after a press that ended off the control: a mouse let go elsewhere, a finger that scrolled away', async () => {
        show(<DateMenu title={TITLE} onBook={() => undefined} onDay={() => undefined} />);

        // The mouse goes down and opens the menu; no click comes to the control, and Escape closes it.
        rest();
        pointerDown(control(), 'mouse');
        await settle();
        expect(items()).toHaveLength(2);
        key(document.activeElement, 'Escape');
        await settle();
        expect(document.querySelector('.admin-menu')).toBeNull();

        press(control());
        await settle();
        expect(items()).toHaveLength(2);

        // A finger goes down on the open menu's control and closes it; no click comes of the scroll.
        pointerDown(control(), 'touch');
        await settle();
        expect(document.querySelector('.admin-menu')).toBeNull();

        rest();
        press(control());
        await settle();
        expect(items()).toHaveLength(2);
    });

    it('leaves out an item the viewer may not take, and is no menu at all where neither is left, the press opening the day', async () => {
        const onDay = vi.fn();
        show(<DateMenu title={TITLE} onClosure={() => undefined} onDay={onDay} />);
        await open();
        expect(items().map((item) => item.textContent)).toEqual([NEW_CLOSURE, OPEN_DAY].map((name) => cal.text(name)));
        key(document.activeElement, 'Escape');
        await settle();

        show(<DateMenu title={TITLE} onDay={onDay} />);
        expect(control()?.hasAttribute('aria-haspopup')).toBe(false);
        press(control());
        await settle();

        expect(document.querySelector('.admin-menu')).toBeNull();
        expect(onDay).toHaveBeenCalledTimes(1);
    });

    it('marks the tenant\'s today as the current date', () => {
        show(<DateMenu title={TITLE} current onDay={() => undefined} />);
        expect(control()?.getAttribute('aria-current')).toBe('date');

        show(<DateMenu title={TITLE} onDay={() => undefined} onBook={() => undefined} />);
        expect(control()?.hasAttribute('aria-current')).toBe(false);
    });
});
