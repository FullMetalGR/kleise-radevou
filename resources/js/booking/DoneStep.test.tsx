import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ENGLISH_WORDS, keyed, said } from '../bookingWords';
import { DoneStep, headingFor, RECEIVED_HEADING, RECEIVED_SENTENCE, sentenceFor } from './DoneStep';
import { BOOKING_LINES_EN } from './lines.generated';
import type { LineKey, Words } from './words';

/*
 * Step done: a success disc hidden from assistive technology, then one paragraph, mounted
 * empty and filled one effect later, its sentence chosen by the server's returned status alone
 * and never by the catalog's approval flag, which can disagree. "Request sent" and "confirmed"
 * are deliberately different screens. No Back, no further controls. The tables answer keys of
 * booking.php and the step renders with the keyed table, once with the English file.
 */

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

async function render(status: string, words?: Words, atVenue?: boolean): Promise<void> {
    await act(() => {
        root.render(keyed(<DoneStep status={status} atVenue={atVenue} />, words));
    });
}

function polite(): HTMLElement | null {
    return container.querySelector('[role="status"]');
}

describe('the sentence table', () => {
    it.each<[string, LineKey]>([
        ['confirmed', 'done.sentences.confirmed'],
        ['awaiting_approval', 'done.sentences.awaiting_approval'],
        ['awaiting_payment', 'done.sentences.awaiting_payment'],
        ['pending', RECEIVED_SENTENCE],
        ['cancelled', RECEIVED_SENTENCE],
        ['a status this build has never heard of', RECEIVED_SENTENCE],
    ])('answers %s with its own sentence', (status, expected) => {
        expect(sentenceFor(status)).toBe(expected);
    });

    it('keeps request sent and confirmed as two different screens', () => {
        expect(sentenceFor('awaiting_approval')).not.toBe(sentenceFor('confirmed'));
    });

    it('tells a confirmed booking paid at the venue so, and changes no other status\'s sentence', () => {
        expect(sentenceFor('confirmed', true)).toBe('done.sentences.at_venue');
        expect(sentenceFor('awaiting_approval', true)).toBe('done.sentences.awaiting_approval');
        expect(sentenceFor('pending', true)).toBe(RECEIVED_SENTENCE);
    });
});

describe('the heading table, the frame\'s title on done', () => {
    it.each<[string, LineKey]>([
        ['confirmed', 'done.headings.confirmed'],
        ['awaiting_approval', 'done.headings.awaiting_approval'],
        ['awaiting_payment', 'done.headings.awaiting_payment'],
        ['pending', RECEIVED_HEADING],
        ['a status this build has never heard of', RECEIVED_HEADING],
    ])('heads %s by the returned status alone', (status, expected) => {
        expect(headingFor(status)).toBe(expected);
    });

    it('keeps request sent and confirmed as two different headings', () => {
        expect(headingFor('awaiting_approval')).not.toBe(headingFor('confirmed'));
    });
});

describe('the paragraph', () => {
    it('mounts empty and takes its text one effect later: the insertion record precedes the text record', async () => {
        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));

        observer.observe(container, { childList: true, subtree: true, characterData: true });

        await render('awaiting_approval');

        records.push(...observer.takeRecords());
        observer.disconnect();

        const inserted = records.findIndex((record) => record.type === 'childList' && Array.from(record.addedNodes).some((node) => node instanceof HTMLElement && node.querySelector('[role="status"]') !== null));
        const texted = records.findIndex((record) => record.target instanceof HTMLElement && record.target.getAttribute('role') === 'status' && record.addedNodes.length > 0);

        expect(inserted).toBeGreaterThanOrEqual(0);
        expect(texted).toBeGreaterThan(inserted);
        expect(polite()?.textContent).toBe(said(sentenceFor('awaiting_approval')));
    });

    it('renders the outcome and no control of any kind', async () => {
        await render('confirmed');

        expect(polite()?.textContent).toBe(said('done.sentences.confirmed'));
        expect(container.querySelectorAll('button')).toHaveLength(0);
        expect(container.querySelectorAll('a')).toHaveLength(0);
        expect(container.querySelector('[role="alert"]')).toBeNull();
    });

    it('reads the English file\'s venue sentence for a confirmed booking paid at the venue', async () => {
        await render('confirmed', ENGLISH_WORDS, true);

        expect(polite()?.textContent).toBe(BOOKING_LINES_EN.done.sentences.at_venue);
    });

    it('reads the English file\'s sentence for the status', async () => {
        await render('awaiting_payment', ENGLISH_WORDS);

        expect(polite()?.textContent).toBe(BOOKING_LINES_EN.done.sentences.awaiting_payment);
    });

    it('draws a success disc before the paragraph, hidden from assistive technology and holding no words', async () => {
        await render('awaiting_approval');

        const disc = container.querySelector('.kr-booking__success');

        expect(disc?.getAttribute('aria-hidden')).toBe('true');
        expect(disc?.textContent).toBe('');
        expect(disc?.querySelector('svg.kr-booking__icon')).not.toBeNull();
        expect(disc?.nextElementSibling).toBe(polite());
        expect(container.querySelector('.kr-booking__step')?.firstElementChild).toBe(disc);
    });
});
