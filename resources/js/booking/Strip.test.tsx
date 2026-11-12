import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { ENGLISH_WORDS, keyed, said } from '../bookingWords';
import type { Catalog } from './catalog';
import { backTargetOf, type FlowState, type Segment, type Step, stripOf } from './flow';
import { EMPTY_DRAFT } from './hold';
import { BOOKING_LINES_EN } from './lines.generated';
import { BACK_LABEL, Strip, STRIP_AHEAD, STRIP_CONFIRM, STRIP_CURRENT, STRIP_LABEL, STRIP_NAMES } from './Strip';
import type { Words } from './words';

/*
 * The crumb: the round back control, then the text trail of the journey's steps before review and
 * Confirm at its end, the finished ones buttons back to themselves exactly where Back's table
 * reaches, the current one aria-current and disabled, the ones ahead disabled, each disabled step
 * saying why within itself in words hidden from sight, and Confirm never a button. The answers are
 * the summary card's, so the crumb holds none. On a step change the trail's own scroll offset
 * brings the current crumb into view, for the phone's one-line trail. Rendered with the keyed
 * table, and once with the English file.
 */

const CATALOG: Catalog = {
    services: [
        { id: 1, name: 'Haircut', description: '', durationMin: 45, priceMinor: 3500, currency: 'EUR', category: null, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }, { id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] },
        { id: 4, name: 'Beard trim', description: '', durationMin: 20, priceMinor: 1500, currency: 'EUR', category: null, staff: [{ id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] },
        { id: 5, name: 'Head spa', description: '', durationMin: 30, priceMinor: 5000, currency: 'JPY', category: null, staff: [] },
    ],
    maxChainLength: 5,
};

const STAFFED: Segment[] = [{ serviceId: 1, staffId: null }];
const UNSTAFFED: Segment[] = [{ serviceId: 5, staffId: null }];

const STATE: FlowState = {
    step: 'details',
    segments: [{ serviceId: 1, staffId: 2 }, { serviceId: 4, staffId: null }],
    window: 0,
    selectedDay: '2026-04-07',
    chosenStart: '2026-04-07T07:00:00+00:00',
    conflict: null,
    detailsNotice: null,
    draft: { ...EMPTY_DRAFT, name: '  Casey Customer ' },
    booking: null,
    reviewNotice: null,
    expired: false,
    recovering: false,
    start: 'service',
    carried: null,
};

/** The strip as step and status pairs, one string each. */
function read(step: Step, segments: readonly Segment[], start: Step, catalog: Catalog | undefined = CATALOG): string[] {
    return stripOf(step, segments, catalog, start).map((entry) => `${entry.step}:${entry.status}`);
}

/** Every step Back reaches from a step, pressed again and again, in strip order. */
function backChain(step: Step, segments: readonly Segment[], start: Step): Step[] {
    const chain: Step[] = [];

    for (let back = backTargetOf(step, segments, CATALOG, start); back !== null; back = backTargetOf(back, segments, CATALOG, start)) {
        chain.unshift(back);
    }

    return chain;
}

describe('the strip\'s table', () => {
    it.each<[string, Step, readonly Segment[], Step, string[]]>([
        ['a bare mount on service before a segment', 'service', [], 'service', ['service:current', 'when:ahead', 'details:ahead']],
        ['a bare mount on service with a staffed segment', 'service', STAFFED, 'service', ['service:current', 'staff:ahead', 'when:ahead', 'details:ahead']],
        ['staff, from service', 'staff', STAFFED, 'service', ['service:finished', 'staff:current', 'when:ahead', 'details:ahead']],
        ['when, from service through staff', 'when', STAFFED, 'service', ['service:finished', 'staff:finished', 'when:current', 'details:ahead']],
        ['when, from service with no staff to choose', 'when', UNSTAFFED, 'service', ['service:finished', 'when:current', 'details:ahead']],
        ['details, from service through staff', 'details', STAFFED, 'service', ['service:finished', 'staff:finished', 'when:finished', 'details:current']],
        ['staff, a mount naming a service', 'staff', STAFFED, 'staff', ['staff:current', 'when:ahead', 'details:ahead']],
        ['when, a mount naming a service', 'when', STAFFED, 'staff', ['staff:finished', 'when:current', 'details:ahead']],
        ['when, a mount naming a service nobody performs', 'when', UNSTAFFED, 'staff', ['when:current', 'details:ahead']],
        ['when, a mount naming both', 'when', STAFFED, 'when', ['when:current', 'details:ahead']],
        ['details, a mount naming both', 'details', STAFFED, 'when', ['when:finished', 'details:current']],
        ['review', 'review', STAFFED, 'service', []],
        ['done', 'done', STAFFED, 'service', []],
    ])('reads %s', (_name, step, segments, start, expected) => {
        expect(read(step, segments, start)).toEqual(expected);
    });

    it('shows the staff step while on it before the catalogue has answered', () => {
        expect(read('staff', STAFFED, 'staff', undefined)).toEqual(['staff:current', 'when:ahead', 'details:ahead']);
    });

    it.each<[Step, readonly Segment[], Step]>([
        ['staff', STAFFED, 'service'],
        ['when', STAFFED, 'service'],
        ['when', UNSTAFFED, 'service'],
        ['when', STAFFED, 'staff'],
        ['when', UNSTAFFED, 'staff'],
        ['when', STAFFED, 'when'],
        ['details', STAFFED, 'service'],
        ['details', UNSTAFFED, 'staff'],
        ['details', STAFFED, 'when'],
    ])('finishes on %s exactly the steps Back reaches from it (%j, started on %s)', (step, segments, start) => {
        const finished = stripOf(step, segments, CATALOG, start).filter((entry) => entry.status === 'finished').map((entry) => entry.step);

        expect(finished).toEqual(backChain(step, segments, start));
    });
});

let container: HTMLDivElement;
let root: Root;
let onGo: Mock<(step: Step) => void>;

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    onGo = vi.fn<(step: Step) => void>();
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

async function render(state: FlowState, busy = false, words?: Words): Promise<void> {
    await act(() => {
        root.render(keyed(<Strip state={state} catalog={CATALOG} busy={busy} onGo={onGo} />, words));
    });
}

function crumbs(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__crumb'));
}

function crumb(step: Step): HTMLButtonElement {
    const found = crumbs().find((candidate) => candidate.value === step);

    if (found === undefined) {
        throw new Error(`No step ${step} on the strip.`);
    }

    return found;
}

function back(): HTMLButtonElement | null {
    return container.querySelector<HTMLButtonElement>('.kr-booking__back');
}

describe('the crumb', () => {
    it('is an ordered list named for what it is, one button per step carrying its step as its value, then Confirm, never a button', async () => {
        await render(STATE);

        const list = container.querySelector('ol.kr-booking__crumbs');

        expect(list?.getAttribute('aria-label')).toBe(said(STRIP_LABEL));
        expect(crumbs().map((candidate) => candidate.value)).toEqual(['service', 'staff', 'when', 'details']);
        expect(crumbs().map((candidate) => candidate.querySelector('.kr-booking__crumb-name')?.textContent)).toEqual([said(STRIP_NAMES.service), said(STRIP_NAMES.staff), said(STRIP_NAMES.when), said(STRIP_NAMES.details)]);

        const items = Array.from(list?.children ?? []);
        const end = items[items.length - 1];

        expect(items).toHaveLength(5);
        expect(end?.textContent).toBe(said(STRIP_CONFIRM));
        expect(end?.querySelector('button')).toBeNull();
    });

    it('reads the English file\'s names, its trail\'s end and its back control\'s name', async () => {
        await render(STATE, false, ENGLISH_WORDS);

        expect(crumbs().map((candidate) => candidate.querySelector('.kr-booking__crumb-name')?.textContent)).toEqual([
            BOOKING_LINES_EN.strip.names.service,
            BOOKING_LINES_EN.strip.names.staff,
            BOOKING_LINES_EN.strip.names.when,
            BOOKING_LINES_EN.strip.names.details,
        ]);
        expect(container.querySelector('.kr-booking__crumb-end')?.textContent).toBe(BOOKING_LINES_EN.strip.confirm);
        expect(back()?.getAttribute('aria-label')).toBe(BOOKING_LINES_EN.strip.back);
    });

    it('draws the separators between the entries hidden from assistive technology, so the trail reads as its words alone', async () => {
        await render(STATE);

        const separators = Array.from(container.querySelectorAll('.kr-booking__crumb-sep'));

        expect(separators).toHaveLength(4);
        expect(separators.every((separator) => separator.getAttribute('aria-hidden') === 'true' && separator.textContent === '' && separator.querySelector('svg') !== null)).toBe(true);
        expect(container.querySelector('ol')?.textContent).toBe([STRIP_NAMES.service, STRIP_NAMES.staff, STRIP_NAMES.when, STRIP_NAMES.details, STRIP_CURRENT, STRIP_CONFIRM].map((key) => said(key)).join(''));
    });

    it('makes a finished step a live button reporting itself, the current one aria-current and disabled, the ones ahead disabled, each disabled one saying why within itself', async () => {
        await render({ ...STATE, step: 'staff' });

        expect(crumb('service').disabled).toBe(false);
        expect(crumb('service').getAttribute('aria-current')).toBeNull();
        expect(crumb('service').querySelector('.kr-booking__crumb-state')).toBeNull();
        expect(crumb('staff').disabled).toBe(true);
        expect(crumb('staff').getAttribute('aria-current')).toBe('step');
        expect(crumb('staff').querySelector('.kr-booking__crumb-state')?.textContent).toBe(said(STRIP_CURRENT));
        expect(crumb('when').disabled).toBe(true);
        expect(crumb('when').querySelector('.kr-booking__crumb-state')?.textContent).toBe(said(STRIP_AHEAD));
        expect(crumb('details').textContent).toContain(said(STRIP_AHEAD));
        expect(crumbs().filter((candidate) => candidate.getAttribute('aria-current') !== null)).toHaveLength(1);

        await act(() => {
            crumb('service').click();
        });

        expect(onGo).toHaveBeenCalledTimes(1);
        expect(onGo).toHaveBeenCalledWith('service');
    });

    it('holds no answers: the summary card states the chain, the people, the day and the time', async () => {
        await render(STATE);

        expect(container.querySelector('.kr-booking__crumb-answer')).toBeNull();
        expect(container.textContent).not.toContain('Haircut');
        expect(container.textContent).not.toContain('Casey');
    });

    it('disables the finished steps and the back control too while a hold flies', async () => {
        await render(STATE, true);

        expect(crumbs().map((candidate) => candidate.disabled)).toEqual([true, true, true, true]);
        expect(crumb('when').querySelector('.kr-booking__crumb-state')).toBeNull();
        expect(back()?.disabled).toBe(true);
    });

    it('renders nothing on review and on done', async () => {
        await render({ ...STATE, step: 'review' });

        expect(container.innerHTML).toBe('');

        await render({ ...STATE, step: 'done' });

        expect(container.innerHTML).toBe('');
    });

    it('carries no live region and no pressed state', async () => {
        await render(STATE);

        expect(container.querySelector('[role], [aria-live], [aria-pressed]')).toBeNull();
    });
});

describe('the trail on a step change', () => {
    /** Lays the trail out as a phone would: a narrow list whose crumbs sit side by side beyond its edge. */
    function layOut(): { readonly list: HTMLElement; readonly scrolled: number[] } {
        const list = container.querySelector<HTMLElement>('.kr-booking__crumbs') as HTMLElement;
        const scrolled: number[] = [];
        let offset = 0;

        list.getBoundingClientRect = () => ({ left: 10, width: 200, top: 0, height: 20, right: 210, bottom: 20, x: 10, y: 0, toJSON: () => undefined });
        Object.defineProperty(list, 'scrollLeft', {
            configurable: true,
            get: () => offset,
            set: (value: number) => {
                offset = value;
                scrolled.push(value);
            },
        });
        // A crumb's rectangle moves with the trail's scroll, as it does on screen.
        Array.from(list.querySelectorAll<HTMLElement>('.kr-booking__crumb')).forEach((crumb, index) => {
            crumb.getBoundingClientRect = () => ({ left: 10 + index * 120 - offset, width: 100, top: 0, height: 20, right: 110 + index * 120 - offset, bottom: 20, x: 10 + index * 120 - offset, y: 0, toJSON: () => undefined });
        });

        return { list, scrolled };
    }

    it('writes the trail\'s scroll offset so the current crumb is centred, never below zero, and only when the step changes', async () => {
        await render({ ...STATE, step: 'service' });

        const { scrolled } = layOut();

        await render({ ...STATE, step: 'service' });

        expect(scrolled).toEqual([]);

        await render({ ...STATE, step: 'when' });

        // The third crumb spans 240 to 340 from the trail's edge: its middle, 290, less half the
        // width.
        expect(scrolled).toEqual([190]);

        await render({ ...STATE, step: 'service' });

        expect(scrolled).toEqual([190, 0]);
    });

    it('scrolls nothing else: the page and the host keep their place', async () => {
        const scrollIntoView = vi.fn();
        const original = Element.prototype.scrollIntoView;

        Element.prototype.scrollIntoView = scrollIntoView;

        try {
            await render({ ...STATE, step: 'staff' });
            await render({ ...STATE, step: 'details' });

            expect(scrollIntoView).not.toHaveBeenCalled();
        } finally {
            Element.prototype.scrollIntoView = original;
        }
    });
});

describe('the back control', () => {
    it.each<[string, Step, readonly Segment[], Step]>([
        ['details, back to when', 'details', STAFFED, 'service'],
        ['when, back to staff', 'when', STAFFED, 'service'],
        ['when with nobody to choose, back to service', 'when', UNSTAFFED, 'service'],
        ['staff, back to service', 'staff', STAFFED, 'service'],
        ['when on a mount naming a service, back to staff', 'when', STAFFED, 'staff'],
    ])('goes where Back\'s table goes, the last finished step: %s', async (_name, step, segments, start) => {
        await render({ ...STATE, step, segments, start });

        expect(back()?.getAttribute('aria-label')).toBe(said(BACK_LABEL));
        expect(back()?.textContent).toBe('');
        expect(back()?.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
        expect(back()?.disabled).toBe(false);

        await act(() => {
            back()?.click();
        });

        expect(onGo).toHaveBeenCalledWith(backTargetOf(step, segments, CATALOG, start));
        expect(backTargetOf(step, segments, CATALOG, start)).toBe(stripOf(step, segments, CATALOG, start).filter((entry) => entry.status === 'finished').at(-1)?.step);
    });

    it.each<[string, Step, readonly Segment[], Step]>([
        ['service', 'service', STAFFED, 'service'],
        ['staff on a mount naming a service', 'staff', STAFFED, 'staff'],
        ['when on a mount naming both', 'when', STAFFED, 'when'],
    ])('is absent where no previous step exists: %s', async (_name, step, segments, start) => {
        await render({ ...STATE, step, segments, start });

        expect(back()).toBeNull();
        expect(container.querySelector('.kr-booking__crumbs')).not.toBeNull();
    });

    it('comes before the trail', async () => {
        await render(STATE);

        expect(back()?.compareDocumentPosition(container.querySelector('.kr-booking__crumbs') as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });
});
