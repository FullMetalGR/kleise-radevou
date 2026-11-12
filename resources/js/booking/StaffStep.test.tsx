import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { ENGLISH_WORDS, keyed, said } from '../bookingWords';
import { ApiError, TRANSPORT_SENTENCE } from './api';
import { CONTINUE_LABEL } from './Estimate';
import type { Catalog } from './catalog';
import type { Segment } from './flow';
import { BOOKING_LINES_EN } from './lines.generated';
import { LOADING_SERVICES_SENTENCE, type CatalogState } from './ServiceStep';
import type { Words } from './words';
import { ANY_PROFESSIONAL, HIDE_PROFILE, MAXIMUM_AVAILABILITY, StaffStep, VIEW_PROFILE } from './StaffStep';

/*
 * Step staff: one picker per segment whose service lists staff, nothing for one that lists
 * nobody, Any professional first and reporting null, a concrete id only for a named person, and
 * segments keyed by index. The choices are cards: the first a disc of crossed arrows over its
 * name and "for maximum availability", each named one the photo or the initials, the name and
 * the title, the pressed one marked by a check for sight alone; the bio sits behind View
 * profile beside the card. Rendered with the keyed table, and once with the English file.
 */

/** The first choice's whole text: its name and the line under it. */
const ANY = `${said(ANY_PROFESSIONAL)} ${said(MAXIMUM_AVAILABILITY)}`;

const CATALOG: Catalog = {
    services: [
        { id: 1, name: 'Haircut', description: '', durationMin: 45, priceMinor: 3500, currency: 'EUR', category: null, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }, { id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] },
        { id: 4, name: 'Beard trim', description: '', durationMin: 20, priceMinor: 1500, currency: 'EUR', category: null, staff: [{ id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] },
        { id: 5, name: 'Head spa', description: '', durationMin: 30, priceMinor: 5000, currency: 'JPY', category: null, staff: [] },
    ],
    maxChainLength: 5,
};

const READY: CatalogState = { data: CATALOG, error: null, isPending: false };

const PHOTO = 'https://salon-x.kleiseradevou.gr/images/staff-0123456789abcdef.png';

const BIO = 'Ten years at the chair. Ask about balayage.';

const PROFILED: CatalogState = {
    data: {
        services: [
            {
                id: 1, name: 'Haircut', description: '', durationMin: 45, priceMinor: 3500, currency: 'EUR', category: null,
                staff: [
                    { id: 2, name: 'Elena Papadaki', photoUrl: PHOTO, title: 'Senior stylist', bio: BIO },
                    { id: 3, name: 'Nikos  Georgiou', photoUrl: null, title: 'Barber', bio: null },
                    { id: 6, name: ' maria ', photoUrl: null, title: null, bio: 'Weekends only.' },
                ],
            },
        ],
        maxChainLength: 5,
    },
    error: null,
    isPending: false,
};

let container: HTMLDivElement;
let root: Root;
let onChoose: Mock<(index: number, staffId: number | null) => void>;

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    onChoose = vi.fn<(index: number, staffId: number | null) => void>();
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

async function render(catalog: CatalogState, segments: readonly Segment[], words?: Words): Promise<void> {
    await act(() => {
        root.render(keyed(<StaffStep catalog={catalog} segments={segments} onChoose={onChoose} />, words));
    });
}

function pickers(): HTMLElement[] {
    return Array.from(container.querySelectorAll<HTMLElement>('.kr-booking__staff'));
}

function choicesOf(picker: HTMLElement): HTMLButtonElement[] {
    return Array.from(picker.querySelectorAll<HTMLButtonElement>('.kr-booking__choice'));
}

async function press(el: HTMLElement): Promise<void> {
    await act(() => {
        el.focus();
        el.click();
    });
}

describe('StaffStep', () => {
    it('renders one picker per segment whose service lists staff, nothing for one that lists nobody', async () => {
        await render(READY, [{ serviceId: 5, staffId: null }, { serviceId: 1, staffId: null }, { serviceId: 99, staffId: null }]);

        expect(pickers()).toHaveLength(1);
        expect(pickers()[0]?.querySelector('.kr-booking__subtitle')?.textContent).toBe('Haircut');
        expect(pickers()[0]?.getAttribute('role')).toBe('group');
        expect(pickers()[0]?.getAttribute('aria-labelledby')).toBe(pickers()[0]?.querySelector('.kr-booking__subtitle')?.id);
    });

    it('reads the English file\'s first choice and its line', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }], ENGLISH_WORDS);

        expect(choicesOf(pickers()[0] as HTMLElement)[0]?.textContent).toBe(`${BOOKING_LINES_EN.staff.any} ${BOOKING_LINES_EN.staff.any_line}`);
    });

    it('puts Any professional first as the default and states the pressed choice in ARIA', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }, { serviceId: 4, staffId: 3 }]);

        const [first, second] = pickers();

        expect(choicesOf(first as HTMLElement).map((choice) => [choice.textContent, choice.getAttribute('aria-pressed')])).toEqual([
            [ANY, 'true'],
            ['Elena', 'false'],
            ['Nikos', 'false'],
        ]);
        expect(choicesOf(second as HTMLElement).map((choice) => [choice.textContent, choice.getAttribute('aria-pressed')])).toEqual([
            [ANY, 'false'],
            ['Nikos', 'true'],
        ]);
    });

    it('marks the pressed card with a check hidden from assistive technology, and no other', async () => {
        await render(READY, [{ serviceId: 1, staffId: 2 }]);

        const choices = choicesOf(pickers()[0] as HTMLElement);
        const marks = choices.map((choice) => choice.querySelector('.kr-booking__choice-mark'));

        expect(marks.map((mark) => mark !== null)).toEqual([false, true, false]);
        expect(marks[1]?.getAttribute('aria-hidden')).toBe('true');
        expect(marks[1]?.querySelector('svg.kr-booking__icon')?.getAttribute('aria-hidden')).toBe('true');
        expect(marks[1]?.textContent).toBe('');
        expect(pickers()[0]?.querySelector('.kr-booking__people')?.tagName).toBe('UL');
    });

    it('reports a concrete id for a named person and null for Any professional, by segment index', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }, { serviceId: 1, staffId: null }]);

        const [first, second] = pickers();

        await press(choicesOf(second as HTMLElement)[2] as HTMLElement);
        expect(onChoose).toHaveBeenLastCalledWith(1, 3);

        await press(choicesOf(first as HTMLElement)[1] as HTMLElement);
        expect(onChoose).toHaveBeenLastCalledWith(0, 2);

        await press(choicesOf(first as HTMLElement)[0] as HTMLElement);
        expect(onChoose).toHaveBeenLastCalledWith(0, null);
        expect(onChoose).toHaveBeenCalledTimes(3);
    });

    it('renders each named choice with the photo or the initials in a disc, then the name and the title, and Any professional with crossed arrows in its disc', async () => {
        await render(PROFILED, [{ serviceId: 1, staffId: 2 }]);

        const [none, elena, nikos, maria] = choicesOf(pickers()[0] as HTMLElement) as [HTMLButtonElement, HTMLButtonElement, HTMLButtonElement, HTMLButtonElement];

        expect(none.textContent).toBe(ANY);
        expect(none.querySelector('.kr-booking__person-name')?.textContent).toBe(said(ANY_PROFESSIONAL));
        expect(none.querySelector('.kr-booking__person-title')?.textContent).toBe(said(MAXIMUM_AVAILABILITY));
        expect(none.querySelector('.kr-booking__face')?.tagName).toBe('SPAN');
        expect(none.querySelector('.kr-booking__face')?.getAttribute('aria-hidden')).toBe('true');
        expect(none.querySelector('.kr-booking__face')?.hasAttribute('data-initials')).toBe(false);
        expect(none.querySelector('.kr-booking__face svg.kr-booking__icon')).not.toBeNull();
        expect(none.querySelector('img')).toBeNull();

        const photo = elena.querySelector('img.kr-booking__face');
        expect(photo?.getAttribute('src')).toBe(PHOTO);
        expect(photo?.getAttribute('alt')).toBe('');
        expect(elena.querySelector('.kr-booking__person-name')?.textContent).toBe('Elena Papadaki');
        expect(elena.querySelector('.kr-booking__person-title')?.textContent).toBe('Senior stylist');
        expect(elena.textContent).toBe('Elena Papadaki Senior stylist');
        expect(elena.getAttribute('aria-pressed')).toBe('true');

        const disc = nikos.querySelector('.kr-booking__face');
        expect(disc?.tagName).toBe('SPAN');
        expect(disc?.getAttribute('data-initials')).toBe('NG');
        expect(disc?.getAttribute('aria-hidden')).toBe('true');
        expect(nikos.querySelector('img')).toBeNull();
        expect(nikos.textContent).toBe('Nikos  Georgiou Barber');

        expect(maria.querySelector('.kr-booking__face')?.getAttribute('data-initials')).toBe('M');
        expect(maria.querySelector('.kr-booking__person-title')).toBeNull();
        expect(maria.textContent).toBe(' maria ');
    });

    it('discloses the bio inline under the row behind View profile, its own button beside the choice, reading Hide profile while open and pressing nobody', async () => {
        await render(PROFILED, [{ serviceId: 1, staffId: 3 }]);

        const picker = pickers()[0] as HTMLElement;
        const profiles = Array.from(picker.querySelectorAll<HTMLButtonElement>('.kr-booking__profile'));
        const pressed = () => choicesOf(picker).map((choice) => choice.getAttribute('aria-pressed'));

        expect(profiles.map((profile) => profile.textContent)).toEqual([said(VIEW_PROFILE), said(VIEW_PROFILE)]);

        const [elena] = profiles as [HTMLButtonElement];
        const row = elena.closest('.kr-booking__item');
        expect(elena.closest('.kr-booking__choice')).toBeNull();
        expect(elena.getAttribute('type')).toBe('button');
        expect(row?.querySelector('.kr-booking__person-name')?.textContent).toBe('Elena Papadaki');
        expect(elena.getAttribute('aria-expanded')).toBe('false');
        expect(picker.querySelector('.kr-booking__bio')).toBeNull();
        expect(picker.querySelector('[role="dialog"]')).toBeNull();

        await press(elena);

        const bio = row?.querySelector('.kr-booking__bio');
        expect(bio?.textContent).toBe(BIO);
        expect(elena.getAttribute('aria-controls')).toBe(bio?.id);
        expect(elena.getAttribute('aria-expanded')).toBe('true');
        expect(elena.textContent).toBe(said(HIDE_PROFILE));
        expect(row?.lastElementChild).toBe(bio);
        expect(picker.querySelectorAll('.kr-booking__bio')).toHaveLength(1);
        expect(document.activeElement).toBe(elena);
        expect(onChoose).not.toHaveBeenCalled();
        expect(pressed()).toEqual(['false', 'false', 'true', 'false']);

        await press(elena);

        expect(picker.querySelector('.kr-booking__bio')).toBeNull();
        expect(elena.textContent).toBe(said(VIEW_PROFILE));
        expect(elena.getAttribute('aria-expanded')).toBe('false');
        expect(onChoose).not.toHaveBeenCalled();
    });

    it('holds no Continue of its own: the summary card\'s is the one', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }]);

        expect(Array.from(container.querySelectorAll('button')).map((candidate) => candidate.textContent)).not.toContain(said(CONTINUE_LABEL));
    });

    it('shows a loading line while the catalog is on its way', async () => {
        await render({ data: undefined, error: null, isPending: true }, [{ serviceId: 1, staffId: null }]);

        expect(pickers()).toHaveLength(0);
        expect(container.textContent).toContain(said(LOADING_SERVICES_SENTENCE));
    });

    it('shows the classified failure when the catalog cannot be loaded', async () => {
        await render({ data: undefined, error: new TypeError('Failed to fetch'), isPending: false }, [{ serviceId: 1, staffId: null }]);

        expect(container.querySelector('[role="alert"]')?.textContent).toBe(said(TRANSPORT_SENTENCE));

        await render({ data: undefined, error: new ApiError(429, 'Slow down.'), isPending: false }, [{ serviceId: 1, staffId: null }]);

        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(container.querySelector('[role="status"]')?.textContent).toBe('Slow down.');
    });
});
