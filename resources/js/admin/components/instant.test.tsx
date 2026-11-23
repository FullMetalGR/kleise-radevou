import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { formatInstant, formatInstantDay, formatInstantTime, type TenantClock } from '@/lib/format';
import { Instant } from './instant';
import { mount, type Mounted } from './ui/harness';

/*
 * An instant written as its day and its time in two parts, so a list can set the two apart: the
 * text is the shared formatter's whole, in a 24-hour clock and a 12-hour one, and each part takes
 * the look it is handed. Where the formatter cannot split the instant, the whole text stands alone.
 */

const ATHENS: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const TWELVE_HOUR: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'm/d/Y', timeFormat: 'g:i A' };

const WIRE = '2026-11-19T15:05:00+00:00';

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const part = (name: 'day' | 'time') => view.host.querySelector(`.admin-instant__${name}`);

describe('an instant in two parts', () => {
    it('joins the day and the time with a space into the shared formatter\'s text, in a 24-hour and a 12-hour clock', () => {
        for (const clock of [ATHENS, TWELVE_HOUR, { ...TWELVE_HOUR, dateFormat: 'Y-m-d', timeFormat: 'h:i a' }]) {
            view.render(<Instant wire={WIRE} clock={clock} />);

            expect(`${formatInstantDay(WIRE, clock)} ${formatInstantTime(WIRE, clock)}`).toBe(formatInstant(WIRE, clock));
            expect(view.host.textContent).toBe(formatInstant(WIRE, clock));
            expect([part('day')?.textContent, part('time')?.textContent]).toEqual([formatInstantDay(WIRE, clock), formatInstantTime(WIRE, clock)]);
        }

        expect(view.host.textContent).toBe('2026-11-19 05:05 pm');
    });

    it('gives each part the look it is handed', () => {
        view.render(<Instant wire={WIRE} clock={TWELVE_HOUR} day="text-muted-foreground" time="ms-1 font-bold" />);

        expect(view.host.textContent).toBe('11/19/2026 5:05 PM');
        expect([...(part('day')?.classList ?? [])]).toEqual(['admin-instant__day', 'text-muted-foreground']);
        expect([...(part('time')?.classList ?? [])]).toEqual(['admin-instant__time', 'ms-1', 'font-bold']);
    });

    it('writes the whole text alone where the instant or a format is one the formatter cannot split', () => {
        view.render(<Instant wire="not an instant" clock={ATHENS} day="font-bold" />);

        expect(view.host.textContent).toBe('not an instant');
        expect(part('day')).toBeNull();

        view.render(<Instant wire={WIRE} clock={{ ...ATHENS, timeFormat: 'H:i T' }} day="font-bold" />);

        expect(view.host.textContent).toBe(formatInstant(WIRE, { ...ATHENS, timeFormat: 'H:i T' }));
        expect(part('time')).toBeNull();
    });
});
