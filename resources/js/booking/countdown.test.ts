import { describe, expect, it } from 'vitest';
import { EXPIRED_MILESTONE, formatRemaining, holdIsLive, milestoneOf, NEAR_MILESTONE, remainingSeconds, untilNextTick } from './countdown';

/*
 * The countdown's arithmetic, held as pure functions: the remaining whole seconds by ceiling
 * against the server's deadline, clamped at zero; the reading in minutes and seconds with the
 * minutes unbounded; the milestone for a remaining time; and the split a hold's release is made
 * on.
 */

const DEADLINE = '2026-04-06T08:15:00+00:00';
const DEADLINE_MS = Date.UTC(2026, 3, 6, 8, 15);

describe('the remaining seconds', () => {
    it('rounds up, so 90.5 seconds is 91 and the reading reaches zero only at the deadline', () => {
        expect(remainingSeconds(DEADLINE, DEADLINE_MS - 90500)).toBe(91);
        expect(remainingSeconds(DEADLINE, DEADLINE_MS - 1)).toBe(1);
        expect(remainingSeconds(DEADLINE, DEADLINE_MS)).toBe(0);
    });

    it('clamps at zero once the deadline has passed', () => {
        expect(remainingSeconds(DEADLINE, DEADLINE_MS + 5000)).toBe(0);
    });
});

describe('the reading', () => {
    it('is minutes and seconds, the seconds padded and the minutes unbounded', () => {
        expect(formatRemaining(91)).toBe('1:31');
        expect(formatRemaining(60)).toBe('1:00');
        expect(formatRemaining(9)).toBe('0:09');
        expect(formatRemaining(0)).toBe('0:00');
        expect(formatRemaining(125 * 60 + 7)).toBe('125:07');
    });
});

describe('the milestones', () => {
    it('says nothing at a minute or more, the near sentence below one, and the run-out sentence at zero', () => {
        expect(milestoneOf(61)).toBeNull();
        expect(milestoneOf(60)).toBeNull();
        expect(milestoneOf(59)).toBe(NEAR_MILESTONE);
        expect(milestoneOf(1)).toBe(NEAR_MILESTONE);
        expect(milestoneOf(0)).toBe(EXPIRED_MILESTONE);
        expect(NEAR_MILESTONE).toBe('countdown.near');
        expect(EXPIRED_MILESTONE).toBe('countdown.expired');
    });
});

describe('the next tick', () => {
    it('lands where the reading next changes, and a whole second on from a change', () => {
        expect(untilNextTick(DEADLINE, DEADLINE_MS - 90500)).toBe(500);
        expect(untilNextTick(DEADLINE, DEADLINE_MS - 90000)).toBe(1000);
    });
});

describe('whether the hold is still live', () => {
    it('is the deadline strictly ahead of now, and a hold with no deadline is live', () => {
        expect(holdIsLive(DEADLINE, DEADLINE_MS - 1)).toBe(true);
        expect(holdIsLive(DEADLINE, DEADLINE_MS)).toBe(false);
        expect(holdIsLive(DEADLINE, DEADLINE_MS + 1)).toBe(false);
        expect(holdIsLive(null, DEADLINE_MS)).toBe(true);
    });
});
