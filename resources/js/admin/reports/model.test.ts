import { describe, expect, it } from 'vitest';
import { formatPercent, type TenantClock } from '@/lib/format';
import { ADMIN_LINES_EN } from '../lines.generated';
import { stringsOf } from '@/lib/strings';
import { keyedReports, keyedShell } from '@/testing/keyed';
import type { ReportsStrings } from '@/types';
import { unitFigure } from '@/lib/names';
import { changeSentence, channelLabel, chartAxis, chartBars, csvUrl, currencyName, customProblem, formatOccupancy, formatRate, hoursOf, noShowRate, rangeLine, reportQuery, sourceLabel, spanDays, statusRows } from './model';

/*
 * The Reports screen's pure rules: the query a range and the filters are read by, this week and
 * every member and service the bare screen; the range's line in the tenant's date format; a
 * custom pair's problems before it is read; each comparison's sentence by the kind of range; the
 * no-show rate and its zero case; and the dialog's words for a source and a channel, None for no
 * channel, anything else verbatim.
 */

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const words = keyedReports();

const ALL = { staff: null, service: null };

describe('the reports model', () => {
    it('spells this week with every member and service as the bare screen, a word by itself, a custom range with its two dates, and each filter by its id', () => {
        expect(reportQuery({ word: 'week', from: '2026-11-02', to: '2026-11-08' }, ALL)).toEqual({});
        expect(reportQuery({ word: 'last_month', from: '2026-10-01', to: '2026-10-31' }, ALL)).toEqual({ range: 'last_month' });
        expect(reportQuery({ word: 'custom', from: '2026-10-01', to: '2026-10-31' }, ALL)).toEqual({ range: 'custom', from: '2026-10-01', to: '2026-10-31' });
        expect(reportQuery({ word: 'week', from: '2026-11-02', to: '2026-11-08' }, { staff: 4, service: null })).toEqual({ staff: '4' });
        expect(reportQuery({ word: 'year', from: '2026-01-01', to: '2026-12-31' }, { staff: 4, service: 7 })).toEqual({ range: 'year', staff: '4', service: '7' });
    });

    it('states the range in one whole sentence of its word with its dates in the tenant\'s format, a single day once', () => {
        expect(rangeLine({ word: 'week', from: '2026-10-19', to: '2026-10-25' }, CLOCK, words)).toBe(words.text('filters.stated.week', { from: '19/10/2026', to: '25/10/2026' }));
        expect(rangeLine({ word: 'today', from: '2026-10-21', to: '2026-10-21' }, CLOCK, words)).toBe(words.text('filters.stated.today', { date: '21/10/2026' }));
        expect(rangeLine({ word: 'yesterday', from: '2026-10-20', to: '2026-10-20' }, CLOCK, words)).toBe(words.text('filters.stated.yesterday', { date: '20/10/2026' }));
        expect(rangeLine({ word: 'last_month', from: '2026-09-01', to: '2026-09-30' }, CLOCK, words)).toBe(words.text('filters.stated.last_month', { from: '01/09/2026', to: '30/09/2026' }));
        expect(rangeLine({ word: 'custom', from: '2026-10-01', to: '2026-10-31' }, { ...CLOCK, dateFormat: 'Y-m-d' }, words)).toBe(words.text('filters.stated.custom', { from: '2026-10-01', to: '2026-10-31' }));
        expect(rangeLine({ word: 'custom', from: '2026-10-05', to: '2026-10-05' }, CLOCK, words)).toBe(words.text('filters.stated.custom_day', { date: '05/10/2026' }));
    });

    it('counts a pair\'s days, both ends, and names a custom pair\'s problem: the To before the From, more than 366 days, or none', () => {
        expect(spanDays('2026-10-05', '2026-10-05')).toBe(1);
        expect(spanDays('2026-10-24', '2026-10-26')).toBe(3);
        expect(spanDays('2028-01-01', '2028-12-31')).toBe(366);
        expect(customProblem('2026-10-14', '2026-10-05')).toBe('to_before_from');
        expect(customProblem('2026-01-01', '2027-01-02')).toBe('too_long');
        expect(customProblem('2026-01-01', '2027-01-01')).toBeNull();
        expect(customProblem('2026-10-05', '2026-10-05')).toBeNull();
    });

    it('words a change by the kind of range: the day, week, 7 days, 30 days, month and year before, and a custom range\'s days', () => {
        const week = { word: 'week', from: '2026-10-19', to: '2026-10-25' } as const;

        expect(changeSentence({ kind: 'up', percent: 15 }, week, words)).toBe(words.text('compare.week.by', { change: formatPercent(15, words.locale, true) }));
        expect(changeSentence({ kind: 'down', percent: -33 }, week, words)).toBe(words.text('compare.week.by', { change: formatPercent(-33, words.locale, true) }));
        expect(changeSentence({ kind: 'down', percent: 0 }, week, words)).toBe(words.text('compare.week.by', { change: formatPercent(-0, words.locale, true) }));
        expect(changeSentence({ kind: 'same', percent: 0 }, week, words)).toBe(words.text('compare.week.same'));
        expect(changeSentence({ kind: 'none', percent: null }, week, words)).toBe(words.text('compare.week.same'));
        expect(changeSentence({ kind: 'from_zero', percent: null }, week, words)).toBe(words.text('compare.week.from_zero'));

        const up = { kind: 'up', percent: 9 } as const;
        const nine = { change: formatPercent(9, words.locale, true) };

        expect(changeSentence(up, { word: 'today', from: '2026-10-21', to: '2026-10-21' }, words)).toBe(words.text('compare.day.by', nine));
        expect(changeSentence(up, { word: 'yesterday', from: '2026-10-20', to: '2026-10-20' }, words)).toBe(words.text('compare.day.by', nine));
        expect(changeSentence(up, { word: 'last_7_days', from: '2026-10-15', to: '2026-10-21' }, words)).toBe(words.text('compare.last_7_days.by', nine));
        expect(changeSentence(up, { word: 'last_30_days', from: '2026-09-22', to: '2026-10-21' }, words)).toBe(words.text('compare.last_30_days.by', nine));
        expect(changeSentence(up, { word: 'month', from: '2026-10-01', to: '2026-10-31' }, words)).toBe(words.text('compare.month.by', nine));
        expect(changeSentence(up, { word: 'last_month', from: '2026-09-01', to: '2026-09-30' }, words)).toBe(words.text('compare.month.by', nine));
        expect(changeSentence(up, { word: 'year', from: '2026-01-01', to: '2026-12-31' }, words)).toBe(words.text('compare.year.by', nine));
        expect(changeSentence(up, { word: 'custom', from: '2026-10-05', to: '2026-10-14' }, words)).toBe(words.choice('compare.custom.by', 10, nine));
        expect(changeSentence({ kind: 'from_zero', percent: null }, { word: 'custom', from: '2026-10-05', to: '2026-10-05' }, words)).toBe(words.choice('compare.custom.from_zero', 1));

        // The change is one figure in the user's language, its sign and its percent mark ICU's: a
        // thousand and more grouped as the language groups it.
        const big = { kind: 'up', percent: 1250 } as const;
        expect(changeSentence(big, week, keyedReports('el'))).toBe(words.text('compare.week.by', { change: formatPercent(1250, keyedReports('el').locale, true) }));
        expect(changeSentence(big, week, keyedReports('el'))).not.toBe(changeSentence(big, week, words));

        // The file's own lines: one day reads otherwise than ten, and ten names its count.
        const english = stringsOf<ReportsStrings>(ADMIN_LINES_EN.reports, 'en');
        const oneDay = changeSentence(up, { word: 'custom', from: '2026-10-05', to: '2026-10-05' }, english);

        expect(oneDay).not.toBe(changeSentence(up, { word: 'custom', from: '2026-10-05', to: '2026-10-14' }, english));
        expect(changeSentence(up, { word: 'custom', from: '2026-10-05', to: '2026-10-14' }, english)).toContain('10');
        expect(oneDay).toBe(changeSentence(up, { word: 'today', from: '2026-10-21', to: '2026-10-21' }, english));
    });

    it('takes the no-show rate over completed plus no-shows, none where both are zero', () => {
        expect(noShowRate(1, 3)).toBe(0.25);
        expect(noShowRate(0, 4)).toBe(0);
        expect(noShowRate(2, 0)).toBe(1);
        expect(noShowRate(0, 0)).toBeNull();
        expect(formatRate(0.25, 'en-GB')).toBe('25%');
        expect(formatRate(1 / 3, 'en-GB')).toBe('33.3%');
        expect(formatRate(1 / 3, 'el-GR')).toBe('33,3%');
    });

    it('names a source and a channel in the dialog\'s words, None for no channel and anything else verbatim', () => {
        const shell = keyedShell();

        expect(sourceLabel('hosted', shell)).toBe(shell.text('shared.sources.hosted'));
        expect(sourceLabel('kiosk', shell)).toBe('kiosk');
        expect(channelLabel('qr', shell, words)).toBe(shell.text('shared.channels.qr'));
        expect(channelLabel(null, shell, words)).toBe(words.text('tables.no_channel'));
        expect(channelLabel('tiktok', shell, words)).toBe('tiktok');
    });

    it('shows five statuses always, in their order, and the other four beneath only above nought', () => {
        const all = ['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'];

        expect(statusRows(all.map((status) => ({ status, bookings: 0 })))).toEqual(['confirmed', 'completed', 'awaiting_approval', 'cancelled', 'no_show'].map((status) => ({ status, bookings: 0 })));
        expect(statusRows(all.map((status) => ({ status, bookings: status === 'rejected' || status === 'pending' ? 2 : 1 }))).map((row) => `${row.status} ${row.bookings}`)).toEqual([
            'confirmed 1',
            'completed 1',
            'awaiting_approval 1',
            'cancelled 1',
            'no_show 1',
            'awaiting_payment 1',
            'pending 2',
            'rejected 2',
            'expired 1',
        ]);
    });

    it('steps a chart\'s axis in ones, twos or fives of a power of ten, about three steps to a top at or above the largest value', () => {
        expect(chartAxis(5)).toEqual({ top: 6, ticks: [0, 2, 4, 6] });
        expect(chartAxis(255)).toEqual({ top: 300, ticks: [0, 100, 200, 300] });
        expect(chartAxis(10)).toEqual({ top: 10, ticks: [0, 5, 10] });
        expect(chartAxis(1)).toEqual({ top: 1, ticks: [0, 1] });
        expect(chartAxis(0)).toEqual({ top: 1, ticks: [0, 1] });
    });

    it('lays the bars out: 32 units a bucket up to eleven, narrower past it, 120 units of height to the top, none for zero', () => {
        const week = chartBars([0, 4, 5]);

        expect(week.width).toBe(36 + 3 * 32);
        expect(week.bars).toEqual([
            { index: 1, x: 72, y: 136 - 80, width: 24, height: 80 },
            { index: 2, x: 104, y: 136 - 100, width: 24, height: 100 },
        ]);
        expect(week.centers).toEqual([52, 84, 116]);
        expect(week.ticks).toEqual([
            { value: 0, y: 136 },
            { value: 2, y: 96 },
            { value: 4, y: 56 },
            { value: 6, y: 16 },
        ]);

        const month = chartBars(Array.from({ length: 31 }, () => 1));

        expect(month.width).toBeCloseTo(36 + 352);
        expect(month.bars[0]?.width).toBeCloseTo((352 / 31) * 0.75);
    });

    it('names the CSV of the page\'s own query, the bare file for this week over everything', () => {
        expect(csvUrl({ word: 'week', from: '2026-11-02', to: '2026-11-08' }, ALL)).toBe('/admin/reports/csv');
        expect(csvUrl({ word: 'custom', from: '2026-10-01', to: '2026-10-31' }, { staff: 4, service: 7 })).toBe('/admin/reports/csv?range=custom&from=2026-10-01&to=2026-10-31&staff=4&service=7');
    });

    it('writes minutes as the tiles do, whole hours alone and the minutes beside them where there are any', () => {
        expect(hoursOf(2400, words)).toBe(unitFigure(40, 'hour', 'en'));
        expect(hoursOf(1185, words)).toBe(words.text('tiles.hours_minutes', { hours: unitFigure(19, 'hour', 'en'), minutes: unitFigure(45, 'minute', 'en') }));
    });

    it('writes an occupancy to one decimal always', () => {
        expect(formatOccupancy(71.25, 'en')).toBe('71.3%');
        expect(formatOccupancy(100, 'en')).toBe('100.0%');
    });

    it('names a currency in words where the locale has a name for it, its code where it has none', () => {
        expect(currencyName('EUR', 'en')).toBe('euros');
        expect(currencyName('XYZ', 'en')).toBe('XYZ');
    });
});
