import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { formatAmount, type TenantClock } from '@/lib/format';
import { keyedWizard } from '@/testing/keyed';
import { ANY_STAFF, addSegment, type Catalog, chooseDay, chooseService, chooseSlot, chooseStaff, courtesy, initial, lengthLabel, type State } from './model';
import { Summary } from './Summary';

/*
 * The booking summary beside every step: a line per chosen service with its price, the times
 * and the member once a start is chosen, the day and time on a chip, then the chain's duration
 * and the total of its prices. Every word is read through the keyed wizard group.
 */

const words = keyedWizard();

const CATALOG: Catalog = {
    services: [
        { id: 1, name: 'Haircut', duration_min: 45, processing_min: 0, price_minor: 3500, currency: 'EUR', staff: [2, 3] },
        { id: 3, name: 'Colour', duration_min: 60, processing_min: 30, price_minor: 8000, currency: 'EUR', staff: [2, 3] },
    ],
    staff: [
        { id: 2, name: 'Elena Papadaki' },
        { id: 3, name: 'Nikos Georgiou' },
    ],
    members: 3,
};

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const TODAY = '2026-11-02';

const TEN = '2026-11-03T08:00:00+00:00';

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => {
    mounted.unmount();
});

/** Haircut on Elena, then Colour on anyone. */
function chain(): State {
    let state = chooseService(courtesy(initial(TODAY), CATALOG), 0, 1, CATALOG);
    state = chooseStaff(state, 0, 2);

    return chooseService(addSegment(state), 1, 3, CATALOG);
}

function draw(state: State, overrides: { catalog?: Catalog; staffReal?: boolean } = {}): HTMLElement {
    mounted.render(<Summary state={state} catalog={overrides.catalog ?? CATALOG} clock={CLOCK} granularity={5} staffReal={overrides.staffReal ?? true} words={words} className="extra" />);
    const card = mounted.host.querySelector<HTMLElement>('.admin-wizard-summary');

    if (card === null) {
        throw new Error('No summary was drawn.');
    }

    return card;
}

function texts(card: HTMLElement, selector: string): (string | null)[] {
    return [...card.querySelectorAll(selector)].map((each) => each.textContent);
}

/** True when a class list names the mono face, the name spelled in two parts so no stylesheet gains it. */
function mono(element: Element | null): boolean {
    return [...(element?.classList ?? [])].includes(['font', 'mono'].join('-'));
}

describe('the booking summary', () => {
    it('heads the card and gives one line per chosen service with its price, with no times and no chip before a start is chosen', () => {
        const card = draw(addSegment(chooseService(courtesy(initial(TODAY), CATALOG), 0, 1, CATALOG)));

        expect(card.querySelector('h3')?.textContent).toBe(words.text('summary.title'));
        expect(texts(card, '.admin-wizard-summary__service')).toEqual(['Haircut']);
        expect(texts(card, '.admin-wizard-summary__price')).toEqual([formatAmount(3500, 'EUR', words.locale)]);
        expect(card.querySelector('.admin-wizard-summary__span')).toBeNull();
        expect(card.querySelector('.admin-wizard-summary__when')).toBeNull();
    });

    it('draws no line for a chain with no service chosen yet', () => {
        const card = draw(initial(TODAY));

        expect(card.querySelector('.admin-wizard-summary__lines li')).toBeNull();
        expect(card.querySelector('.admin-wizard-summary__total')).toBeNull();
    });

    it('gives each line its own start and end once a start is chosen, with its member or Any staff where members are offered, and the day and time on a chip', () => {
        const state = chooseSlot(chooseDay(chain(), '2026-11-03'), TEN);
        const card = draw(state);

        expect(texts(card, '.admin-wizard-summary__span')).toEqual([
            words.text('joined', { first: words.text('summary.span', { time: '10:00', end: '10:45' }), second: 'Elena Papadaki' }),
            words.text('joined', { first: words.text('summary.span', { time: '10:45', end: '11:45' }), second: words.text(ANY_STAFF) }),
        ]);
        expect(card.querySelector('.admin-wizard-summary__when')?.textContent).toBe(words.text('joined', { first: 'Tue 03/11/2026', second: '10:00' }));
        expect(card.querySelector('.admin-wizard-summary__when svg')?.getAttribute('aria-hidden')).toBe('true');
    });

    it('sets each line\'s start and end in lining figures of the body face, never the mono face', () => {
        const spans = [...draw(chooseSlot(chooseDay(chain(), '2026-11-03'), TEN)).querySelectorAll('.admin-wizard-summary__span')];

        expect(spans).toHaveLength(2);
        expect(spans.map((span) => span.classList.contains('tabular-nums'))).toEqual([true, true]);
        expect(spans.map((span) => mono(span))).toEqual([false, false]);
    });

    it('gives the times alone where the member choice is not offered', () => {
        const card = draw(chooseSlot(chooseDay(chain(), '2026-11-03'), TEN), { staffReal: false });

        expect(texts(card, '.admin-wizard-summary__span')).toEqual([words.text('summary.span', { time: '10:00', end: '10:45' }), words.text('summary.span', { time: '10:45', end: '11:45' })]);
    });

    it('gives the duration of one and of two segments and the total of their prices', () => {
        const one = draw(chooseService(courtesy(initial(TODAY), CATALOG), 0, 1, CATALOG));

        expect(one.querySelector('.admin-wizard-summary__duration')?.textContent).toBe(lengthLabel(45, words));
        expect(one.querySelector('.admin-wizard-summary__total')?.textContent).toBe(formatAmount(3500, 'EUR', words.locale));

        const two = draw(chain());

        expect(two.querySelector('.admin-wizard-summary__duration')?.textContent).toBe(lengthLabel(105, words));
        expect(two.querySelector('.admin-wizard-summary__total')?.textContent).toBe(formatAmount(11500, 'EUR', words.locale));
        expect(texts(two, '.admin-wizard-summary__totals dt')).toEqual([words.text('summary.duration'), words.text('summary.total')]);
    });

    it('leaves the total out when the chosen services do not share one currency, each line keeping its own price', () => {
        const mixed: Catalog = { ...CATALOG, services: CATALOG.services.map((service) => (service.id === 3 ? { ...service, currency: 'USD' } : service)) };
        const card = draw(chain(), { catalog: mixed });

        expect(card.querySelector('.admin-wizard-summary__total')).toBeNull();
        expect(texts(card, '.admin-wizard-summary__totals dt')).toEqual([words.text('summary.duration')]);
        expect(texts(card, '.admin-wizard-summary__price')).toEqual([formatAmount(3500, 'EUR', words.locale), formatAmount(8000, 'USD', words.locale)]);
    });

    it('is a soft panel with no ring, breaking long names inside it, the day chip across its width, its lines and chip hidden below the two-column width and the total in the display font', () => {
        const card = draw(chooseSlot(chooseDay(chain(), '2026-11-03'), TEN));

        expect(card.className.split(' ')).toEqual(expect.arrayContaining(['rounded-inner', 'bg-muted', 'px-4.5', 'pt-4.5', 'pb-4', 'wrap-anywhere', 'extra']));
        expect(card.className).not.toMatch(/\bring-/);
        expect(card.querySelector('h3')?.className.split(' ')).toEqual(expect.arrayContaining(['text-base', 'font-semibold']));
        expect(card.querySelector('.admin-wizard-summary__lines')?.className.split(' ')).toContain('@max-3xl:hidden');
        expect(card.querySelector('.admin-wizard-summary__when')?.className.split(' ')).toEqual(expect.arrayContaining(['@max-3xl:hidden', 'bg-card']));
        expect(card.querySelector('.admin-wizard-summary__when')?.className.split(' ')).not.toContain('self-start');
        expect(card.innerHTML).not.toMatch(/@max-xl:/);
        expect(card.querySelector('.admin-wizard-summary__totals')?.className.split(' ')).toEqual(expect.arrayContaining(['border-dashed', 'border-tint-strong']));
        expect(card.querySelector('.admin-wizard-summary__total')?.className.split(' ')).toEqual(expect.arrayContaining(['font-display', 'text-dialog-title']));
    });
});
