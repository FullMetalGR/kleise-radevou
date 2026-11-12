import { wallClockOf } from './time';

/*
 * The period control's split: a day's starts grouped at 12:00 and 17:00 on the tenant's wall
 * clock into Morning, Afternoon and Evening, the admin wizard's split. The hour is read in the
 * tenant's zone through the carried digits, as every label is, so the browser's clock and zone
 * decide nothing (7.1). Starts stay the wire values whole.
 */

export type PeriodName = 'Morning' | 'Afternoon' | 'Evening';

export interface Period {
    readonly name: PeriodName;
    readonly starts: readonly string[];
}

const NAMES: readonly PeriodName[] = ['Morning', 'Afternoon', 'Evening'];

/**
 * The period a start falls in on the tenant's wall clock: before 12:00 is Morning, from 12:00
 * to before 17:00 Afternoon, and from 17:00 Evening.
 */
export function periodOf(start: string, timezone: string): PeriodName {
    const { hour } = wallClockOf(start, timezone);

    return hour < 12 ? 'Morning' : hour < 17 ? 'Afternoon' : 'Evening';
}

/**
 * A day's starts by period, in the day's order, each start in its order; a period holding no
 * start is left out, so an empty day has none.
 */
export function periodsOf(starts: readonly string[], timezone: string): Period[] {
    const named = starts.map((start) => ({ start, name: periodOf(start, timezone) }));

    return NAMES.map((name) => ({ name, starts: named.filter((entry) => entry.name === name).map((entry) => entry.start) })).filter((period) => period.starts.length > 0);
}
