import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ENGLISH_WORDS, keyed, KEYED_WORDS, said, saidBy } from '../bookingWords';
import type { Catalog } from './catalog';
import { formatDuration } from './duration';
import { ANY_PROFESSIONAL_LINE, BRIEF_LINE, briefOf, CONTINUE_LABEL, Estimate, ESTIMATE_LABEL, ESTIMATE_TOTAL, PICK_A_SERVICE, PICK_A_TIME_LABEL, REMOVE_LABEL, WITH_STAFF_LINE, type Proceed } from './Estimate';
import type { Segment, StripName } from './flow';
import { BOOKING_LINES_EN } from './lines.generated';
import { formatMoney } from './money';
import { PLACEHOLDER_NAME } from './ServiceStep';
import { formatDay, formatMoment, wallClockOf } from './time';
import { choose } from './choose';
import type { Words } from './words';

/*
 * The summary card: from the service step on, the business at its head, a line per chosen
 * service with its duration under it and its price in the row's own currency at the right, the
 * people from the staff step on, the day and the time once chosen, the total duration and the
 * total price labelled as an estimate, and the one Continue. Never the number the customer
 * confirms; a named region whose total announces nothing. On a phone the same card is the
 * bottom bar: the sum and a brief count beside Continue. Rendered with the keyed table, every
 * duration, price and day spelled in its words, and once with the English file.
 */

const CATALOG: Catalog = {
    services: [
        { id: 1, name: 'Haircut', description: '', durationMin: 45, priceMinor: 3500, currency: 'EUR', category: null, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }] },
        { id: 4, name: 'Beard trim', description: '', durationMin: 20, priceMinor: 1500, currency: 'EUR', category: null, staff: [{ id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] },
        { id: 5, name: 'Head spa', description: '', durationMin: 30, priceMinor: 5000, currency: 'JPY', category: null, staff: [] },
    ],
    maxChainLength: 5,
};

const TIMEZONE = 'Europe/Athens';

const CONTINUE: Proceed = { label: CONTINUE_LABEL, disabled: false };

/** The locale the keyed words format in, the page's language on this machine. */
const LOCALE = KEYED_WORDS.locale;

/** A duration as the keyed words spell it. */
function lasting(minutes: number): string {
    return formatDuration(minutes, KEYED_WORDS);
}

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

interface Rendering {
    readonly segments: readonly Segment[];
    readonly step?: StripName;
    readonly answered?: boolean;
    readonly selectedDay?: string | null;
    readonly chosenStart?: string | null;
    readonly proceed?: Proceed;
    readonly onRemove?: (index: number) => void;
    readonly words?: Words;
}

/** Renders the card over the catalogue, or over none where the test says the catalogue has not answered. */
async function render({ segments, step = 'service', answered = true, selectedDay = null, chosenStart = null, proceed = CONTINUE, onRemove, words }: Rendering): Promise<void> {
    await act(() => {
        root.render(
            keyed(
                <Estimate
                tenantName="Luna Hair Studio"
                catalog={answered ? CATALOG : undefined}
                segments={segments}
                step={step}
                timezone={TIMEZONE}
                selectedDay={selectedDay}
                chosenStart={chosenStart}
                proceed={proceed}
                onRemove={onRemove}
                />,
                words,
            ),
        );
    });
}

function chain(...ids: number[]): Segment[] {
    return ids.map((serviceId) => ({ serviceId, staffId: null }));
}

function texts(selector: string): (string | null)[] {
    return Array.from(container.querySelectorAll(selector)).map((node) => node.textContent);
}

function estimate(): HTMLElement {
    return container.querySelector('.kr-booking__estimate') as HTMLElement;
}

function proceedButton(): HTMLButtonElement {
    return container.querySelector('.kr-booking__continue') as HTMLButtonElement;
}

describe('the summary card', () => {
    it('carries the empty modifier while it holds no segment, for the phone bar to hide itself by, and drops it from the first', async () => {
        await render({ segments: [] });

        expect(estimate().classList.contains('kr-booking__estimate--empty')).toBe(true);

        await render({ segments: chain(1) });

        expect(estimate().classList.contains('kr-booking__estimate--empty')).toBe(false);
        expect(estimate().classList.contains('kr-booking__estimate')).toBe(true);
    });

    it('heads itself with the business: an initial on a rounded accent square hidden from assistive technology, then the name', async () => {
        await render({ segments: [] });

        const disc = container.querySelector('.kr-booking__estimate-disc');

        expect(disc?.textContent).toBe('L');
        expect(disc?.getAttribute('aria-hidden')).toBe('true');
        expect(container.querySelector('.kr-booking__estimate-business')?.textContent).toBe('Luna Hair Studio');
    });

    it('renders before the first segment, saying to pick a service, with no lines and no total', async () => {
        await render({ segments: [] });

        expect(estimate()).not.toBeNull();
        expect(container.querySelector('.kr-booking__estimate-empty')?.textContent).toBe(said(PICK_A_SERVICE));
        expect(container.querySelector('.kr-booking__estimate-items')).toBeNull();
        expect(container.querySelector('.kr-booking__estimate-total')).toBeNull();
    });

    it('names each chosen service with its duration under it and its price, then the total duration and the total price, under the estimate label', async () => {
        await render({ segments: chain(1, 4, 1) });

        expect(container.querySelector('.kr-booking__estimate-label')?.textContent).toBe(said(ESTIMATE_LABEL));
        expect(texts('.kr-booking__estimate-items .kr-booking__estimate-name')).toEqual(['Haircut', 'Beard trim', 'Haircut']);
        expect(texts('.kr-booking__estimate-detail')).toEqual([lasting(45), lasting(20), lasting(45)]);
        expect(texts('.kr-booking__estimate-price')).toEqual([formatMoney(3500, 'EUR', LOCALE), formatMoney(1500, 'EUR', LOCALE), formatMoney(3500, 'EUR', LOCALE)]);
        expect(container.querySelector('.kr-booking__estimate-total')?.textContent).toBe(`${said(ESTIMATE_TOTAL)} ${lasting(110)} ${formatMoney(8500, 'EUR', LOCALE)}`);
        expect(container.querySelector('.kr-booking__estimate-empty')).toBeNull();
    });

    it('formats every price in its row\'s own currency and scale', async () => {
        await render({ segments: chain(5) });

        expect(texts('.kr-booking__estimate-price')).toEqual([formatMoney(5000, 'JPY', LOCALE)]);
        expect(container.querySelector('.kr-booking__estimate-sum')?.textContent).toBe(formatMoney(5000, 'JPY', LOCALE));
        expect(container.querySelector('.kr-booking__estimate-sum')?.textContent).not.toBe(formatMoney(5000, 'EUR', LOCALE));
    });

    it('sums no prices across two currencies: each row keeps its own, and the total line holds the duration alone', async () => {
        await render({ segments: chain(1, 5) });

        expect(texts('.kr-booking__estimate-price')).toEqual([formatMoney(3500, 'EUR', LOCALE), formatMoney(5000, 'JPY', LOCALE)]);
        expect(container.querySelector('.kr-booking__estimate-sum')).toBeNull();
        expect(container.querySelector('.kr-booking__estimate-total')?.textContent).toBe(`${said(ESTIMATE_TOTAL)} ${lasting(75)}`);
    });

    it('names a service the catalogue no longer lists by the placeholder and leaves it out of both totals', async () => {
        await render({ segments: chain(1, 99) });

        expect(texts('.kr-booking__estimate-items .kr-booking__estimate-name')).toEqual(['Haircut', said(PLACEHOLDER_NAME)]);
        expect(texts('.kr-booking__estimate-price')).toEqual([formatMoney(3500, 'EUR', LOCALE)]);
        expect(container.querySelector('.kr-booking__estimate-total')?.textContent).toBe(`${said(ESTIMATE_TOTAL)} ${lasting(45)} ${formatMoney(3500, 'EUR', LOCALE)}`);
    });

    it('shows no lines and no total before the catalogue has answered, naming nothing it cannot know', async () => {
        await render({ segments: chain(1), answered: false, step: 'staff' });

        expect(estimate()).not.toBeNull();
        expect(container.querySelector('.kr-booking__estimate-items')).toBeNull();
        expect(container.querySelector('.kr-booking__estimate-total')).toBeNull();
        expect(container.textContent).not.toContain(said(PLACEHOLDER_NAME));
    });

    it('says who performs each service from the staff step on: the person named, or any professional, and nothing for a service nobody is chosen for', async () => {
        await render({ segments: [{ serviceId: 1, staffId: 2 }, { serviceId: 4, staffId: null }, { serviceId: 5, staffId: null }], step: 'staff' });

        expect(texts('.kr-booking__estimate-detail')).toEqual([said(WITH_STAFF_LINE, { duration: lasting(45), staff: 'Elena' }), said(ANY_PROFESSIONAL_LINE, { duration: lasting(20) }), lasting(30)]);

        await render({ segments: [{ serviceId: 1, staffId: 2 }], step: 'service' });

        expect(texts('.kr-booking__estimate-detail')).toEqual([lasting(45)]);
    });

    it('states the day once one is chosen on when, and the day and the time once a time is chosen, on the tenant\'s clock; neither before', async () => {
        await render({ segments: chain(1), step: 'staff', selectedDay: '2026-04-07' });

        expect(container.querySelector('.kr-booking__estimate-when')).toBeNull();

        await render({ segments: chain(1), step: 'when', selectedDay: '2026-04-07' });

        expect(container.querySelector('.kr-booking__estimate-when')?.textContent).toBe(formatDay('2026-04-07', LOCALE));

        const wall = wallClockOf('2026-04-07T07:00:00+00:00', TIMEZONE);

        await render({ segments: chain(1), step: 'details', selectedDay: '2026-04-07', chosenStart: '2026-04-07T07:00:00+00:00' });

        expect(container.querySelector('.kr-booking__estimate-when')?.textContent).toBe(formatMoment(wall, LOCALE));
    });

    it('offers a remove control named for each line where it is handed one, the service step\'s, and none elsewhere', async () => {
        const onRemove = vi.fn<(index: number) => void>();

        await render({ segments: chain(1, 99), onRemove });

        const removes = Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__remove'));

        expect(removes.map((remove) => remove.getAttribute('aria-label'))).toEqual([said(REMOVE_LABEL, { service: 'Haircut' }), said(REMOVE_LABEL, { service: said(PLACEHOLDER_NAME) })]);
        expect(removes.every((remove) => remove.textContent === '' && remove.querySelector('svg[aria-hidden="true"]') !== null)).toBe(true);

        await act(() => {
            removes[1]?.click();
        });

        expect(onRemove).toHaveBeenCalledWith(1);

        await render({ segments: chain(1, 4), step: 'staff' });

        expect(container.querySelectorAll('.kr-booking__remove')).toHaveLength(0);
    });

    it('carries the one Continue as it is handed: its label, whether it is disabled, its press or the form it submits', async () => {
        const onPress = vi.fn<() => void>();

        await render({ segments: chain(1), proceed: { label: CONTINUE_LABEL, disabled: false, onPress } });

        expect(proceedButton().type).toBe('button');
        expect(proceedButton().textContent).toBe(said(CONTINUE_LABEL));

        await act(() => {
            proceedButton().click();
        });

        expect(onPress).toHaveBeenCalledTimes(1);

        await render({ segments: chain(1), step: 'when', proceed: { label: PICK_A_TIME_LABEL, disabled: true } });

        expect(proceedButton().disabled).toBe(true);
        expect(proceedButton().textContent).toBe(said(PICK_A_TIME_LABEL));

        await render({ segments: chain(1), step: 'details', proceed: { label: CONTINUE_LABEL, disabled: false, form: 'the-form' } });

        expect(proceedButton().type).toBe('submit');
        expect(proceedButton().getAttribute('form')).toBe('the-form');
    });

    it('spells the phone bar\'s brief: the count of services and the total duration', async () => {
        await render({ segments: chain(1, 4) });

        expect(container.querySelector('.kr-booking__estimate-brief')?.textContent).toBe(briefOf(2, 65, KEYED_WORDS));
        expect(briefOf(1, 45, KEYED_WORDS)).toBe(saidBy(BRIEF_LINE, 1, { duration: lasting(45) }));
        expect(briefOf(2, 65, KEYED_WORDS)).toBe(saidBy(BRIEF_LINE, 2, { duration: lasting(65) }));
    });

    it('reads the English file\'s label, its line before the first service, its total\'s word and its Continue', async () => {
        await render({ segments: [], words: ENGLISH_WORDS });

        expect(container.querySelector('.kr-booking__estimate-label')?.textContent).toBe(BOOKING_LINES_EN.estimate.label);
        expect(container.querySelector('.kr-booking__estimate-empty')?.textContent).toBe(BOOKING_LINES_EN.estimate.pick_a_service);
        expect(proceedButton().textContent).toBe(BOOKING_LINES_EN.estimate.continue);

        await render({ segments: chain(1), words: ENGLISH_WORDS });

        expect(container.querySelector('.kr-booking__estimate-word')?.textContent).toBe(BOOKING_LINES_EN.estimate.total);
        expect(container.querySelector('.kr-booking__estimate-brief')?.textContent).toBe(choose(BOOKING_LINES_EN.estimate.brief, 1).replace(':count', '1').replace(':duration', formatDuration(45, ENGLISH_WORDS)));
        expect(container.querySelector('.kr-booking__remove')).toBeNull();

        await render({ segments: [{ serviceId: 1, staffId: 2 }], step: 'staff', words: ENGLISH_WORDS, onRemove: () => undefined });

        expect(texts('.kr-booking__estimate-detail')).toEqual([BOOKING_LINES_EN.estimate.staff.replace(':duration', formatDuration(45, ENGLISH_WORDS)).replace(':staff', 'Elena')]);
        expect(container.querySelector('.kr-booking__remove')?.getAttribute('aria-label')).toBe(BOOKING_LINES_EN.estimate.remove.replace(':service', 'Haircut'));
    });

    it('is a region named by its visible label whose total announces nothing: no live region anywhere in it', async () => {
        await render({ segments: chain(1, 4), onRemove: () => undefined });

        const region = estimate();
        const label = container.querySelector('.kr-booking__estimate-label') as HTMLElement;

        expect(region.tagName).toBe('ASIDE');
        expect(region.getAttribute('aria-labelledby')).toBe(label.id);
        expect(region.hasAttribute('aria-live')).toBe(false);
        expect(region.querySelector('[role], [aria-live]')).toBeNull();
        expect(Array.from(region.querySelectorAll('button')).map((control) => control.getAttribute('aria-label') ?? control.textContent)).toEqual([said(REMOVE_LABEL, { service: 'Haircut' }), said(REMOVE_LABEL, { service: 'Beard trim' }), said(CONTINUE_LABEL)]);
    });
});
