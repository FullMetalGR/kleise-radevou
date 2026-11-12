import { act, useRef, useState, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ENGLISH_WORDS, keyed, KEYED_WORDS, said, saidBy } from '../bookingWords';
import { ApiError, TRANSPORT_SENTENCE } from './api';
import { type Catalog, type CatalogService, OTHER_HEADING } from './catalog';
import { formatDuration } from './duration';
import { CONTINUE_LABEL, Estimate, REMOVE_LABEL } from './Estimate';
import type { Segment } from './flow';
import { BOOKING_LINES_EN } from './lines.generated';
import { formatMoney } from './money';
import { CHIPS_LABEL, EMPTY_SENTENCE, LIMIT_SENTENCE, LOADING_SERVICES_SENTENCE, PLACEHOLDER_NAME, REMOVED_SENTENCE, ServiceStep, STALE_SENTENCE, useRemoval, type CatalogState } from './ServiceStep';
import { choose } from './choose';
import type { Words } from './words';

/*
 * Step service: the picker states in precedence, the cards that toggle in place, the chips, the
 * cap, and the removal rules, which hold on a pressed card and on the summary card's remove
 * controls alike. Rendered under a harness owning the segments the way the flow does, with the
 * summary card beside the step inside one root, so removal and focus run against a real commit.
 * The step holds no Continue: the summary card's is the one. Rendered with the keyed table, and
 * once with the English file.
 */

const CATALOG: Catalog = {
    services: [
        { id: 1, name: 'Haircut', description: 'Wash, cut and finish.', durationMin: 45, priceMinor: 3500, currency: 'EUR', category: null, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }] },
        { id: 4, name: 'Beard trim', description: '', durationMin: 20, priceMinor: 1500, currency: 'EUR', category: null, staff: [] },
        { id: 5, name: 'Head spa', description: '', durationMin: 90, priceMinor: 5000, currency: 'JPY', category: null, staff: [] },
        { id: 6, name: 'Fifty euro', description: '', durationMin: 120, priceMinor: 5000, currency: 'EUR', category: null, staff: [] },
    ],
    maxChainLength: 3,
};

const READY: CatalogState = { data: CATALOG, error: null, isPending: false };

let container: HTMLDivElement;
let root: Root;
let segmentsSeen: readonly Segment[] = [];

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    segmentsSeen = [];
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

/**
 * The step and the summary card in one root, the removal the flow's hook, the segments state
 * the flow's reducer would hold.
 */
function Harness({ catalog, initial }: { readonly catalog: CatalogState; readonly initial: readonly Segment[] }): ReactNode {
    const frame = useRef<HTMLDivElement>(null);
    const [segments, setSegments] = useState<readonly Segment[]>(initial);
    const removal = useRemoval(frame, catalog.data, segments, true, (index) => setSegments((current) => current.filter((_segment, at) => at !== index)));

    segmentsSeen = segments;

    return (
        <div ref={frame}>
            <ServiceStep
                catalog={catalog}
                segments={segments}
                announcement={removal.announcement}
                onAppend={(service: CatalogService) => setSegments((current) => [...current, { serviceId: service.id, staffId: null }])}
                onRemove={(index) => removal.remove(index, false)}
            />
            <Estimate
                tenantName="Luna Hair Studio"
                catalog={catalog.data}
                segments={segments}
                step="service"
                timezone="Europe/Athens"
                selectedDay={null}
                chosenStart={null}
                proceed={{ label: CONTINUE_LABEL, disabled: segments.length === 0 }}
                onRemove={(index) => removal.remove(index, true)}
            />
        </div>
    );
}

async function render(catalog: CatalogState, initial: readonly Segment[] = [], words?: Words): Promise<void> {
    await act(() => {
        root.render(keyed(<Harness catalog={catalog} initial={initial} />, words));
    });
}

function step(): HTMLElement {
    return container.querySelector('.kr-booking__step') as HTMLElement;
}

function cards(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__service'));
}

function card(name: string): HTMLButtonElement {
    const found = cards().find((candidate) => candidate.querySelector('.kr-booking__service-name')?.textContent === name);

    if (found === undefined) {
        throw new Error(`No card named "${name}".`);
    }

    return found;
}

function removes(): HTMLButtonElement[] {
    return Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__remove'));
}

function announcement(): HTMLElement {
    const regions = Array.from(container.querySelectorAll<HTMLElement>('.kr-booking__step > [role="status"]'));

    return regions[regions.length - 1] as HTMLElement;
}

async function press(el: HTMLElement | null | undefined): Promise<void> {
    if (el === null || el === undefined) {
        throw new Error('Nothing to press.');
    }

    await act(() => {
        el.focus();
        el.click();
    });
}

describe('the picker states', () => {
    it('shows loading and no list while the catalog is on its way', async () => {
        await render({ data: undefined, error: null, isPending: true });

        expect(container.querySelector('.kr-booking__picker')?.getAttribute('aria-busy')).toBe('true');
        expect(container.textContent).toContain(said(LOADING_SERVICES_SENTENCE));
        expect(step().querySelector('ul')).toBeNull();
    });

    it('shapes the loading state as the cards it waits for: a few blank cards under the busy picker, hidden from assistive technology, beside the loading sentence', async () => {
        await render({ data: undefined, error: null, isPending: true });

        const skeleton = container.querySelector('.kr-booking__picker > .kr-booking__skeleton');
        const sentence = Array.from(container.querySelectorAll('.kr-booking__picker > p')).find((line) => line.textContent === said(LOADING_SERVICES_SENTENCE));

        expect(skeleton?.getAttribute('aria-hidden')).toBe('true');
        expect(skeleton?.querySelectorAll('.kr-booking__blank')).toHaveLength(3);
        expect(skeleton?.textContent).toBe('');
        expect(skeleton?.querySelector('button, [role], [aria-live]')).toBeNull();
        expect(sentence?.closest('[aria-hidden]')).toBeNull();
        expect(sentence?.compareDocumentPosition(skeleton as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);

        await render(READY);

        expect(container.querySelector('.kr-booking__skeleton')).toBeNull();

        await render({ data: undefined, error: new TypeError('Failed to fetch'), isPending: false });

        expect(container.querySelector('.kr-booking__skeleton')).toBeNull();
    });

    it('shows a transport failure as an alert with the fixed sentence and no list', async () => {
        await render({ data: undefined, error: new TypeError('Failed to fetch'), isPending: false });

        expect(container.querySelector('[role="alert"]')?.textContent).toBe(said(TRANSPORT_SENTENCE));
        expect(container.textContent).not.toContain('Failed to fetch');
        expect(step().querySelector('ul')).toBeNull();
        expect(container.textContent).not.toContain(said(EMPTY_SENTENCE));
    });

    it('shows a 4xx politely, with the server sentence, one effect after mounting', async () => {
        await render({ data: undefined, error: new ApiError(429, 'Too many requests. Please wait a moment.'), isPending: false });

        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(container.querySelector('[role="status"]')?.textContent).toBe('Too many requests. Please wait a moment.');
    });

    it('reads the English file\'s loading line and its empty sentence', async () => {
        await render({ data: undefined, error: null, isPending: true }, [], ENGLISH_WORDS);

        expect(container.textContent).toContain(BOOKING_LINES_EN.service.loading);

        await render({ data: { services: [], maxChainLength: 5 }, error: null, isPending: false }, [], ENGLISH_WORDS);

        expect(container.textContent).toContain(BOOKING_LINES_EN.service.empty);
    });

    it('shows the empty sentence with no list element at all for an empty catalog', async () => {
        await render({ data: { services: [], maxChainLength: 5 }, error: null, isPending: false });

        expect(container.textContent).toContain(said(EMPTY_SENTENCE));
        expect(step().querySelector('ul')).toBeNull();
        expect(container.querySelector('[role="alert"]')).toBeNull();
    });

    it('lists the services in order otherwise, one card per list item, each a button', async () => {
        await render(READY);

        expect(cards().map((candidate) => candidate.querySelector('.kr-booking__service-name')?.textContent)).toEqual(['Haircut', 'Beard trim', 'Head spa', 'Fifty euro']);
        expect(step().querySelectorAll('ul > li > button')).toHaveLength(4);
    });

    it('keeps the list and adds the polite stale line when a refetch failed', async () => {
        await render(READY);
        await render({ data: CATALOG, error: new TypeError('Failed to fetch'), isPending: false });

        expect(cards()).toHaveLength(4);
        expect(container.querySelector('[role="alert"]')).toBeNull();

        const regions = Array.from(step().querySelectorAll('[role="status"]'));

        expect(regions[0]?.textContent).toBe(said(STALE_SENTENCE));
        expect(regions[0]?.compareDocumentPosition(step().querySelector('ul') as Element)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);

        await render(READY);

        expect(regions[0]?.textContent).toBe('');
    });

    it('holds no Continue, no chain builder and no add button of its own: the summary card states the chain and carries the one Continue', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }]);

        expect(Array.from(step().querySelectorAll('button')).map((candidate) => candidate.textContent)).not.toContain(said(CONTINUE_LABEL));
        expect(step().querySelector('.kr-booking__chain, .kr-booking__segments, .kr-booking__add, .kr-booking__remove')).toBeNull();
    });
});

describe('the category headings and the chips', () => {
    const service = (id: number, name: string, category: string | null): CatalogService => ({ id, name, description: '', durationMin: 30, priceMinor: 1000, currency: 'EUR', category, staff: [] });
    const catalogOf = (services: CatalogService[]): CatalogState => ({ data: { services, maxChainLength: 3 }, error: null, isPending: false });
    const headings = () => Array.from(container.querySelectorAll('.kr-booking__group-heading')).map((heading) => heading.textContent);
    const chips = () => Array.from(container.querySelectorAll<HTMLButtonElement>('.kr-booking__chip'));
    const pressedChips = () => chips().map((chip) => chip.getAttribute('aria-pressed'));
    const FOUR = [service(1, 'Cut', 'Hair'), service(2, 'Manicure', 'Nails'), service(3, 'Consult', null), service(4, 'Colour', 'Hair'), service(5, 'Massage', 'Body'), service(6, 'Facial', 'Face')];

    it('heads the cards by category in catalogue order with Other last, each group its own list', async () => {
        await render(catalogOf([service(1, 'Cut', 'Hair'), service(2, 'Consult', null), service(3, 'Manicure', 'Nails'), service(4, 'Colour', 'Hair')]));

        expect(headings()).toEqual(['Hair', 'Nails', said(OTHER_HEADING)]);
        expect(Array.from(container.querySelectorAll('.kr-booking__group')).map((group) => Array.from(group.querySelectorAll('.kr-booking__service-name')).map((name) => name.textContent))).toEqual([
            ['Cut', 'Colour'],
            ['Manicure'],
            ['Consult'],
        ]);
    });

    it('shows no heading and no chips when every card is under one category or under none', async () => {
        await render(catalogOf([service(1, 'Cut', 'Hair'), service(2, 'Colour', 'Hair')]));
        expect(headings()).toEqual([]);
        expect(chips()).toEqual([]);
        expect(step().querySelectorAll('.kr-booking__list')).toHaveLength(1);

        await render(READY);
        expect(headings()).toEqual([]);
        expect(chips()).toEqual([]);
    });

    it('renders a chip per heading above the list from two headings up', async () => {
        await render(catalogOf([service(1, 'Cut', 'Hair'), service(2, 'Consult', null)]));
        expect(headings()).toEqual(['Hair', said(OTHER_HEADING)]);
        expect(chips().map((chip) => chip.textContent)).toEqual(['Hair', said(OTHER_HEADING)]);

        await render(catalogOf(FOUR));
        expect(chips().map((chip) => chip.textContent)).toEqual(['Hair', 'Nails', 'Body', 'Face', said(OTHER_HEADING)]);
        expect(container.querySelector('.kr-booking__chips')?.getAttribute('aria-label')).toBe(said(CHIPS_LABEL));
        expect(container.querySelector('.kr-booking__chips')?.compareDocumentPosition(container.querySelector('.kr-booking__group') as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });

    it('presses the first chip at mount and the one last pressed after that, one at a time', async () => {
        const original = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = () => undefined;

        try {
            await render(catalogOf(FOUR));
            expect(pressedChips()).toEqual(['true', 'false', 'false', 'false', 'false']);

            await press(chips()[3]);
            expect(pressedChips()).toEqual(['false', 'false', 'false', 'true', 'false']);

            await press(chips()[0]);
            expect(pressedChips()).toEqual(['true', 'false', 'false', 'false', 'false']);
        } finally {
            Element.prototype.scrollIntoView = original;
        }
    });

    it('moves focus and scroll to the heading a chip names', async () => {
        const scrolled: Element[] = [];
        const original = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = function (this: Element) {
            scrolled.push(this);
        };

        try {
            await render(catalogOf(FOUR));
            await press(chips()[2]);

            const body = Array.from(container.querySelectorAll<HTMLElement>('.kr-booking__group-heading'))[2];
            expect(document.activeElement).toBe(body);
            expect(body?.textContent).toBe('Body');
            expect(scrolled).toEqual([body]);
        } finally {
            Element.prototype.scrollIntoView = original;
        }
    });

    it('appends the card a customer presses under a heading as it does in the plain list', async () => {
        await render(catalogOf(FOUR));
        await press(card('Massage'));

        expect(segmentsSeen).toEqual([{ serviceId: 5, staffId: null }]);
    });
});

describe('a card', () => {
    it('is a button holding the name, the duration, the description where set and the price, in order, then the drawn mark hidden from assistive technology', async () => {
        await render(READY);

        const parts = Array.from(card('Haircut').querySelectorAll('[class^="kr-booking__service-"]')).map((part) => part.className);

        expect(parts).toEqual(['kr-booking__service-text', 'kr-booking__service-name', 'kr-booking__service-duration', 'kr-booking__service-description', 'kr-booking__service-price', 'kr-booking__service-mark']);
        expect(card('Haircut').querySelector('.kr-booking__service-description')?.textContent).toBe('Wash, cut and finish.');
        expect(card('Beard trim').querySelector('.kr-booking__service-description')).toBeNull();
        expect(card('Haircut').querySelector('.kr-booking__service-price')?.textContent).toBe(formatMoney(3500, 'EUR', KEYED_WORDS.locale));

        const mark = card('Haircut').querySelector('.kr-booking__service-mark');

        expect(mark?.getAttribute('aria-hidden')).toBe('true');
        expect(mark?.querySelector('svg')).not.toBeNull();
        expect(mark?.textContent).toBe('');
    });

    it('is named by its text alone: the name, the duration, the description and the price', async () => {
        await render(READY);

        expect(card('Haircut').textContent).toBe(`Haircut${formatDuration(45, KEYED_WORDS)}Wash, cut and finish.${formatMoney(3500, 'EUR', KEYED_WORDS.locale)}`);
        expect(card('Haircut').hasAttribute('aria-label')).toBe(false);
    });

    it('spells the duration by the one helper: minutes, whole hours, hours and minutes', async () => {
        await render(READY);

        expect(cards().map((candidate) => candidate.querySelector('.kr-booking__service-duration')?.textContent)).toEqual([45, 20, 90, 120].map((minutes) => formatDuration(minutes, KEYED_WORDS)));
    });

    it('reads 5000 JPY as five thousand yen and 5000 EUR as fifty euro', async () => {
        await render(READY);

        const yen = card('Head spa').querySelector('.kr-booking__service-price')?.textContent ?? '';
        const euro = card('Fifty euro').querySelector('.kr-booking__service-price')?.textContent ?? '';

        expect(yen).toBe(formatMoney(5000, 'JPY', KEYED_WORDS.locale));
        expect(yen).toMatch(/5[^0-9]?000(?![0-9])/);
        expect(yen).not.toMatch(/50[.,]00/);
        expect(euro).toBe(formatMoney(5000, 'EUR', KEYED_WORDS.locale));
        expect(euro).toMatch(/50[.,]00/);
    });
});

describe('toggling a card', () => {
    it('appends on a press of an unpressed card and presses it, focus staying on it, nothing advancing', async () => {
        await render(READY);

        expect(cards().map((candidate) => candidate.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'false', 'false']);
        expect(announcement().textContent).toBe('');

        await press(card('Haircut'));

        expect(segmentsSeen).toEqual([{ serviceId: 1, staffId: null }]);
        expect(card('Haircut').getAttribute('aria-pressed')).toBe('true');
        expect(cards()).toHaveLength(4);
        expect(document.activeElement).toBe(card('Haircut'));
        expect(announcement().textContent).toBe('');
    });

    it('removes that service\'s segment on a press of a pressed card, announcing it, focus staying on the card', async () => {
        await render(READY, [{ serviceId: 4, staffId: null }, { serviceId: 1, staffId: null }]);

        await press(card('Haircut'));

        expect(segmentsSeen).toEqual([{ serviceId: 4, staffId: null }]);
        expect(card('Haircut').getAttribute('aria-pressed')).toBe('false');
        expect(announcement().textContent).toBe(said(REMOVED_SENTENCE, { service: 'Haircut' }));
        expect(document.activeElement).toBe(card('Haircut'));
    });

    it('removes the first of two segments of one service, the card staying pressed for the other', async () => {
        await render(READY, [{ serviceId: 1, staffId: 2 }, { serviceId: 4, staffId: null }, { serviceId: 1, staffId: null }]);

        await press(card('Haircut'));

        expect(segmentsSeen).toEqual([{ serviceId: 4, staffId: null }, { serviceId: 1, staffId: null }]);
        expect(card('Haircut').getAttribute('aria-pressed')).toBe('true');
    });

    it('writes into the region afresh for two identical removals', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }, { serviceId: 1, staffId: null }, { serviceId: 4, staffId: null }]);

        await press(card('Haircut'));

        expect(announcement().textContent).toBe(said(REMOVED_SENTENCE, { service: 'Haircut' }));

        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));

        observer.observe(announcement(), { childList: true, subtree: true, characterData: true });

        await press(card('Haircut'));

        records.push(...observer.takeRecords());
        observer.disconnect();

        expect(announcement().textContent).toBe(said(REMOVED_SENTENCE, { service: 'Haircut' }));
        expect(records.some((record) => record.addedNodes.length > 0)).toBe(true);
        expect(segmentsSeen.map((segment) => segment.serviceId)).toEqual([4]);
    });
});

describe('the chain\'s maximum', () => {
    it('says the limit in the English file\'s words, chosen by the cap the server answered', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }, { serviceId: 4, staffId: null }, { serviceId: 5, staffId: null }], ENGLISH_WORDS);

        expect(container.querySelector('.kr-booking__limit')?.textContent).toBe(choose(BOOKING_LINES_EN.service.limit, 3).replace(':count', '3'));
    });

    it('disables the unpressed cards at the cap the server answered and says above the list how many can be chosen', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }, { serviceId: 4, staffId: null }]);

        expect(cards().some((candidate) => candidate.disabled)).toBe(false);
        expect(container.querySelector('.kr-booking__limit')).toBeNull();

        await press(card('Head spa'));

        expect(cards().map((candidate) => [candidate.querySelector('.kr-booking__service-name')?.textContent, candidate.disabled])).toEqual([
            ['Haircut', false],
            ['Beard trim', false],
            ['Head spa', false],
            ['Fifty euro', true],
        ]);

        const limit = container.querySelector('.kr-booking__limit');

        expect(limit?.textContent).toBe(saidBy(LIMIT_SENTENCE, 3));
        expect(limit?.compareDocumentPosition(step().querySelector('ul') as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });

    it('enables every card again with a removal below the cap', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }, { serviceId: 4, staffId: null }, { serviceId: 5, staffId: null }]);

        expect(card('Fifty euro').disabled).toBe(true);

        await press(card('Beard trim'));

        expect(cards().some((candidate) => candidate.disabled)).toBe(false);
        expect(container.querySelector('.kr-booking__limit')).toBeNull();
    });

    it('disables nothing before the catalog has answered a cap', async () => {
        await render({ data: undefined, error: null, isPending: true }, [{ serviceId: 1, staffId: null }]);

        expect(container.querySelector('.kr-booking__limit')).toBeNull();
        expect(container.querySelectorAll('button[disabled]:not(.kr-booking__continue)')).toHaveLength(0);
    });
});

describe('the summary card\'s remove controls', () => {
    it('announce a removal and re-seat focus on the remove control now at that index', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }, { serviceId: 4, staffId: null }, { serviceId: 5, staffId: null }]);

        expect(removes().map((remove) => remove.getAttribute('aria-label'))).toEqual([said(REMOVE_LABEL, { service: 'Haircut' }), said(REMOVE_LABEL, { service: 'Beard trim' }), said(REMOVE_LABEL, { service: 'Head spa' })]);
        expect(announcement().textContent).toBe('');

        await press(removes()[1]);

        expect(segmentsSeen.map((segment) => segment.serviceId)).toEqual([1, 5]);
        expect(announcement().textContent).toBe(said(REMOVED_SENTENCE, { service: 'Beard trim' }));
        expect(document.activeElement).toBe(removes()[1]);
        expect(removes()[1]?.getAttribute('aria-label')).toBe(said(REMOVE_LABEL, { service: 'Head spa' }));
    });

    it('re-seat focus on the last remove control when the removed one was last', async () => {
        await render(READY, [{ serviceId: 1, staffId: null }, { serviceId: 4, staffId: null }]);

        await press(removes()[1]);

        expect(removes()).toHaveLength(1);
        expect(document.activeElement).toBe(removes()[0]);
        expect(announcement().textContent).toBe(said(REMOVED_SENTENCE, { service: 'Beard trim' }));
    });

    it('re-seat focus on the picker\'s first card when the last segment goes, and still announce', async () => {
        await render(READY, [{ serviceId: 4, staffId: null }]);

        await press(removes()[0]);

        expect(removes()).toHaveLength(0);
        expect(cards()).toHaveLength(4);
        expect(document.activeElement).toBe(card('Haircut'));
        expect(announcement().textContent).toBe(said(REMOVED_SENTENCE, { service: 'Beard trim' }));
    });

    it('name a placeholder for a service the catalog no longer lists', async () => {
        await render(READY, [{ serviceId: 99, staffId: null }]);

        expect(removes()[0]?.getAttribute('aria-label')).toBe(said(REMOVE_LABEL, { service: said(PLACEHOLDER_NAME) }));

        await press(removes()[0]);

        expect(announcement().textContent).toBe(said(REMOVED_SENTENCE, { service: said(PLACEHOLDER_NAME) }));
    });
});
