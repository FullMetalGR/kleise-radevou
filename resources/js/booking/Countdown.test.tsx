import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { keyed, said } from '../bookingWords';
import { Countdown, COUNTDOWN_LABEL } from './Countdown';
import { EXPIRED_MILESTONE, NEAR_MILESTONE } from './countdown';

/*
 * The countdown under fake timers, which is where its zero is proven: a real browser would have
 * to wait a hold's TTL out. The browser clock is the faked Date.now, handed in the way the flow
 * hands in its own, so the component reads no clock it was not given. Rendered with the keyed
 * table, so each sentence is asserted by its key.
 */

const DEADLINE = '2026-04-06T08:15:00+00:00';
const DEADLINE_MS = Date.UTC(2026, 3, 6, 8, 15);

let container: HTMLDivElement;
let root: Root;
let expired: ReturnType<typeof vi.fn<() => void>>;

beforeEach(() => {
    vi.useFakeTimers();
    expired = vi.fn<() => void>();
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
    vi.useRealTimers();
});

const clock = (): number => Date.now();

async function mountAt(before: number, onExpire: () => void = expired): Promise<void> {
    vi.setSystemTime(DEADLINE_MS - before);

    await act(() => {
        root.render(keyed(<Countdown deadline={DEADLINE} now={clock} expired={false} onExpire={onExpire} />));
    });
}

/**
 * Moves the clock a second at most per act, so the render each tick schedules, and the effect
 * that arms the next tick, land before the clock moves on past it.
 */
async function advance(ms: number): Promise<void> {
    for (let left = ms; left > 0; left -= 1000) {
        const step = Math.min(1000, left);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(step);
        });
    }
}

function reading(): string {
    return container.querySelector('.kr-booking__countdown-value')?.textContent ?? '';
}

function milestone(): string | null {
    return container.querySelector('.kr-booking__countdown [role="status"]')?.textContent ?? null;
}

const LIVE_ROLES = ['status', 'alert', 'log', 'marquee', 'timer'];

describe('the reading', () => {
    it('reads 90.5 seconds as 1:31, by ceiling', async () => {
        await mountAt(90500);

        expect(reading()).toBe('1:31');
    });

    it('carries its label beside the reading', async () => {
        await mountAt(90500);

        expect(container.querySelector('.kr-booking__countdown-reading')?.textContent).toBe(`${said(COUNTDOWN_LABEL)} 1:31`);
    });

    it('sits in no element carrying a live region role or an aria-live attribute', async () => {
        await mountAt(90500);

        const value = container.querySelector('.kr-booking__countdown-value');

        expect(value).not.toBeNull();

        for (let node: Element | null = value; node !== null; node = node.parentElement) {
            expect(LIVE_ROLES).not.toContain(node.getAttribute('role'));
            expect(node.hasAttribute('aria-live')).toBe(false);
        }
    });

    it('ticks once a second, each tick where the ceiling steps', async () => {
        await mountAt(90500);

        await advance(499);
        expect(reading()).toBe('1:31');

        await advance(1);
        expect(reading()).toBe('1:30');

        await advance(999);
        expect(reading()).toBe('1:30');

        await advance(1);
        expect(reading()).toBe('1:29');
    });

    it('clamps at zero and stops ticking there', async () => {
        await mountAt(2000);

        await advance(2000);
        expect(reading()).toBe('0:00');

        await advance(10000);
        expect(reading()).toBe('0:00');
        expect(vi.getTimerCount()).toBe(0);
    });

    it('reads minutes unbounded for a long hold', async () => {
        await mountAt(125 * 60 * 1000);

        expect(reading()).toBe('125:00');
    });
});

describe('the milestone region', () => {
    it('is present and empty from the first paint, and still empty at exactly sixty seconds', async () => {
        await mountAt(61000);

        expect(milestone()).toBe('');

        await advance(1000);

        expect(reading()).toBe('1:00');
        expect(milestone()).toBe('');
    });

    it('says the near sentence below a minute, landing after the region existed', async () => {
        await mountAt(60000);

        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));
        const region = container.querySelector('.kr-booking__countdown [role="status"]');

        observer.observe(container, { childList: true, subtree: true, characterData: true });

        await advance(1000);

        records.push(...observer.takeRecords());
        observer.disconnect();

        expect(milestone()).toBe(said(NEAR_MILESTONE));
        expect(container.querySelector('.kr-booking__countdown [role="status"]')).toBe(region);
        expect(records.some((record) => record.target === region && record.addedNodes.length > 0)).toBe(true);
    });

    it('says the run-out sentence at zero', async () => {
        await mountAt(3000);

        await advance(3000);

        expect(milestone()).toBe(said(EXPIRED_MILESTONE));
        expect(container.querySelector('[role="alert"]')).toBeNull();
    });
});

describe('expiry', () => {
    it('fires exactly once at zero, however long after it the component stays', async () => {
        await mountAt(2000);

        await advance(1000);
        expect(expired).not.toHaveBeenCalled();

        await advance(1000);
        expect(expired).toHaveBeenCalledTimes(1);

        await advance(5000);
        expect(expired).toHaveBeenCalledTimes(1);
    });

    it('fires once even when the flow hands it a new callback after zero', async () => {
        await mountAt(0);

        const second = vi.fn<() => void>();

        await act(() => {
            root.render(keyed(<Countdown deadline={DEADLINE} now={clock} expired={false} onExpire={second} />));
        });
        await advance(3000);

        expect(expired).toHaveBeenCalledTimes(1);
        expect(second).not.toHaveBeenCalled();
    });

    it('renders zero and fires at once when mounted with a deadline already past, the region inserted before its text', async () => {
        const records: MutationRecord[] = [];
        const observer = new MutationObserver((batch) => records.push(...batch));

        observer.observe(container, { childList: true, subtree: true, characterData: true });

        await mountAt(-30000);

        records.push(...observer.takeRecords());
        observer.disconnect();

        const inserted = records.findIndex((record) => Array.from(record.addedNodes).some((node) => node instanceof HTMLElement && node.querySelector('[role="status"]') !== null));
        const texted = records.findIndex((record) => record.target instanceof HTMLElement && record.target.getAttribute('role') === 'status' && record.addedNodes.length > 0);

        expect(reading()).toBe('0:00');
        expect(expired).toHaveBeenCalledTimes(1);
        expect(milestone()).toBe(said(EXPIRED_MILESTONE));
        expect(inserted).toBeGreaterThan(-1);
        expect(texted).toBeGreaterThan(inserted);
    });
});

describe('an expiry the flow was told of', () => {
    /**
     * The flow's flag raised by a 410 while the clock still reads time left: the countdown is
     * handed expired and nothing else changes.
     */
    async function toldExpired(): Promise<void> {
        await act(() => {
            root.render(keyed(<Countdown deadline={DEADLINE} now={clock} expired onExpire={expired} />));
        });
    }

    it('reads as run out at once, stops ticking, and empties the near sentence without announcing anything further', async () => {
        await mountAt(50000);

        expect(milestone()).toBe(said(NEAR_MILESTONE));

        await toldExpired();

        expect(reading()).toBe('0:00');
        expect(milestone()).toBe('');

        await advance(60000);

        expect(reading()).toBe('0:00');
        expect(milestone()).toBe('');
        expect(container.querySelector('[role="alert"]')).toBeNull();
    });

    it('never says the near sentence once told, even crossing the minute afterwards', async () => {
        await mountAt(90000);
        await toldExpired();
        await advance(45000);

        expect(milestone()).toBe('');
        expect(reading()).toBe('0:00');
    });

    it('fires no expiry of its own, the flag being up already', async () => {
        await mountAt(5000);
        await toldExpired();
        await advance(10000);

        expect(expired).not.toHaveBeenCalled();
    });

    it('keeps its own run-out sentence when it reached zero itself before the flag came up', async () => {
        await mountAt(1000);
        await advance(1000);

        expect(milestone()).toBe(said(EXPIRED_MILESTONE));

        await toldExpired();

        expect(milestone()).toBe(said(EXPIRED_MILESTONE));
        expect(expired).toHaveBeenCalledTimes(1);
    });
});
