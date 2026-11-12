import { parseInstant } from './time';
import type { LineKey } from './words';

/*
 * The countdown's arithmetic, and the split the manual release of 5.4 is made on. Pure functions
 * of the server's deadline and an instant, so the clock is whatever the caller hands in and a
 * test can hand in its own.
 *
 * The deadline is the server's timestamp and never one computed from the configured TTL: clocks
 * skew, and a client-derived deadline can outlive the server's and offer a confirm guaranteed to
 * fail. Seconds round up, so 90.5 seconds reads as 1:31 and the reading reaches zero only at the
 * true deadline; a floor would show the countdown a second ahead of the deadline it enforces.
 * Remaining time clamps at zero. The reading is minutes and seconds with the minutes unbounded,
 * so a longer hold needs no second format.
 */

const SECOND_MS = 1000;

const MINUTE_S = 60;

export const NEAR_MILESTONE: LineKey = 'countdown.near';

export const EXPIRED_MILESTONE: LineKey = 'countdown.expired';

/**
 * The whole seconds left before the deadline, rounded up and never below zero.
 */
export function remainingSeconds(deadline: string, now: number): number {
    return Math.max(0, Math.ceil((parseInstant(deadline) - now) / SECOND_MS));
}

export function formatRemaining(seconds: number): string {
    const minutes = Math.floor(seconds / MINUTE_S);
    const rest = seconds % MINUTE_S;

    return `${String(minutes)}:${String(rest).padStart(2, '0')}`;
}

/**
 * The key of the sentence a remaining time earns: the run-out sentence at zero, the near one
 * below a minute, and nothing at a minute or more, so at exactly sixty seconds the region is
 * empty.
 */
export function milestoneOf(seconds: number): LineKey | null {
    if (seconds === 0) {
        return EXPIRED_MILESTONE;
    }

    return seconds < MINUTE_S ? NEAR_MILESTONE : null;
}

/**
 * How long until the reading next changes: the part of a second left over, or a whole second
 * when now sits on a boundary. One tick per second, each landing where the ceiling steps, so
 * zero lands on the deadline itself rather than up to a second after it.
 */
export function untilNextTick(deadline: string, now: number): number {
    const over = (parseInstant(deadline) - now) % SECOND_MS;

    return over > 0 ? over : SECOND_MS;
}

/**
 * Whether a hold is still live by its own deadline, 5.4: the deadline strictly ahead of now. A
 * hold the server gave no deadline has none to pass, so it is live until something says it is
 * not; the sweeper owns it either way.
 */
export function holdIsLive(deadline: string | null, now: number): boolean {
    return deadline === null || parseInstant(deadline) > now;
}
