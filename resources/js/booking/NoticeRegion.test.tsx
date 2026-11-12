import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { keyed, said } from '../bookingWords';
import type { Notice } from './api';
import { NoticeRegion } from './NoticeRegion';

/*
 * The notice region: the polite paragraph always there and born empty, its text one effect
 * later, proven by the order of mutation records; the alert paragraph beside it announcing on
 * appearance; two identical notices each a fresh DOM write.
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

function polite(): HTMLElement | null {
    return container.querySelector('[role="status"]');
}

function alert(): HTMLElement | null {
    return container.querySelector('[role="alert"]');
}

async function render(notice: Notice | null): Promise<void> {
    await act(() => {
        root.render(<NoticeRegion notice={notice} />);
    });
}

describe('NoticeRegion', () => {
    it('reads a notice of the app\'s own by its key through the words, its placeholders filled from its values, polite or alert alike', async () => {
        const removed: Notice = { key: 'service.removed', values: { service: 'Haircut' }, tone: 'polite' };

        await act(() => {
            root.render(keyed(<NoticeRegion notice={removed} />));
        });

        expect(polite()?.textContent).toBe(said('service.removed', { service: 'Haircut' }));

        await act(() => {
            root.render(keyed(<NoticeRegion notice={{ ...removed, tone: 'alert' }} />));
        });

        expect(alert()?.textContent).toBe(said('service.removed', { service: 'Haircut' }));
    });

    it('mounts the polite paragraph empty, with no alert, when there is nothing to say', async () => {
        await render(null);

        expect(polite()).not.toBeNull();
        expect(polite()?.textContent).toBe('');
        expect(alert()).toBeNull();
    });

    it('mounts empty and takes its text one effect later: the insertion record precedes the text record', async () => {
        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));

        observer.observe(container, { childList: true, subtree: true, characterData: true });

        await render({ text: 'Your chosen time is still available.', tone: 'polite' });

        records.push(...observer.takeRecords());
        observer.disconnect();

        const insertion = records.findIndex((record) => Array.from(record.addedNodes).some((node) => node instanceof HTMLElement && node.getAttribute('role') === 'status'));
        const text = records.findIndex((record) => record.target instanceof HTMLElement && record.target.getAttribute('role') === 'status' && record.addedNodes.length > 0);

        expect(insertion).toBeGreaterThan(-1);
        expect(text).toBeGreaterThan(insertion);
        expect(polite()?.textContent).toBe('Your chosen time is still available.');
        expect(alert()).toBeNull();
    });

    it('renders an alert paragraph beside an empty polite one for an alert', async () => {
        await render({ text: 'Something went wrong while loading.', tone: 'alert' });

        expect(alert()?.textContent).toBe('Something went wrong while loading.');
        expect(polite()?.textContent).toBe('');
        expect(container.children).toHaveLength(2);
        expect(container.children[0]?.getAttribute('role')).toBe('status');
        expect(container.children[1]?.getAttribute('role')).toBe('alert');
    });

    it('removes the alert and clears the polite text when the notice goes', async () => {
        await render({ text: 'One.', tone: 'polite' });
        await render(null);

        expect(polite()?.textContent).toBe('');
        expect(alert()).toBeNull();

        await render({ text: 'Two.', tone: 'alert' });
        await render(null);

        expect(alert()).toBeNull();
        expect(polite()?.textContent).toBe('');
    });

    it('writes into the region afresh for two consecutive identical notices', async () => {
        await render({ text: 'A sentence the server wrote.', tone: 'polite' });

        const region = polite() as HTMLElement;
        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));

        observer.observe(region, { childList: true, subtree: true, characterData: true });

        await render({ text: 'A sentence the server wrote.', tone: 'polite' });

        records.push(...observer.takeRecords());
        observer.disconnect();

        expect(records.length).toBeGreaterThan(0);
        expect(records.some((record) => record.addedNodes.length > 0)).toBe(true);
        expect(region.textContent).toBe('A sentence the server wrote.');
    });

    it('writes nothing for the same notice object handed down again', async () => {
        const notice: Notice = { text: 'Still here.', tone: 'polite' };

        await render(notice);

        const region = polite() as HTMLElement;
        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));

        observer.observe(region, { childList: true, subtree: true, characterData: true });

        await render(notice);

        records.push(...observer.takeRecords());
        observer.disconnect();

        expect(records).toHaveLength(0);
    });

    it('moves from a polite notice to an alert and back, keeping one polite paragraph throughout', async () => {
        await render({ text: 'Polite.', tone: 'polite' });

        const region = polite();

        await render({ text: 'Alert!', tone: 'alert' });

        expect(polite()).toBe(region);
        expect(polite()?.textContent).toBe('');
        expect(alert()?.textContent).toBe('Alert!');

        await render({ text: 'Polite again.', tone: 'polite' });

        expect(polite()).toBe(region);
        expect(polite()?.textContent).toBe('Polite again.');
        expect(alert()).toBeNull();
    });
});
