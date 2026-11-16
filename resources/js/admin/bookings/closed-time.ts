import { isoWeekday } from '@/lib/day';
import { formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { listOf, weekdayName } from '@/lib/names';
import type { CrossingClosure, CrossingFacts } from '@/lib/visits';
import type { NamedCrossing } from './moving';
import type { BookingsWords } from './words';

/*
 * The reasons a move onto closed time gives, one plain sentence each, from the facts the server
 * read per crossing: a closure of the business or of the member, named by its day or its run of
 * days, its window and its reason; the member's hours that day; the member's weekday off or the
 * business closed that weekday; or the date taken off. Days read as a weekday and a date in the
 * tenant's format, times in its time format, and the weekday names in the viewer's language.
 *
 * A crossing that carries no facts, names a member the move cannot read where the sentence needs
 * one, or has no hours that day and gives no reason this module knows, reads its plain line
 * instead: the service with its member outside working hours or on a closure, or the whole line
 * naming neither. The same sentence said twice, two services in one closure, is said once.
 */

const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;

/** Every reason the crossings give, in their order, each sentence once. */
export function reasonLines(crossings: readonly NamedCrossing[], words: BookingsWords, clock: TenantClock): string[] {
    return [...new Set(crossings.flatMap((crossing) => reasonsOf(crossing, words, clock)))];
}

/**
 * A crossing's plain line: the service with its member, or the whole line naming neither where
 * the move cannot read one.
 */
function crossingLine(crossing: NamedCrossing, words: BookingsWords): string {
    const closure = crossing.kind === 'closure';

    if (crossing.service === null || crossing.staff === null) {
        return words.text(closure ? 'move.confirm.closure_unnamed' : 'move.confirm.hours_unnamed');
    }

    return words.text(closure ? 'move.confirm.closure' : 'move.confirm.hours', { service: crossing.service, staff: crossing.staff });
}

function reasonsOf(crossing: NamedCrossing, words: BookingsWords, clock: TenantClock): string[] {
    const facts = crossing.facts ?? null;

    if (facts === null) {
        return [crossingLine(crossing, words)];
    }

    if (crossing.kind === 'closure') {
        const lines = facts.closures.flatMap((closure) => closureLine(closure, crossing.staff, words, clock) ?? []);

        return lines.length > 0 ? lines : [crossingLine(crossing, words)];
    }

    return [hoursLine(facts, crossing, words, clock)];
}

/** A closure in the way: the business's whatever the member, a member's only with their name. */
function closureLine(closure: CrossingClosure, staff: string | null, words: BookingsWords, clock: TenantClock): string | null {
    const when = whenOf(closure, words, clock);

    if (closure.business) {
        return closure.reason === null ? words.text('move.confirm.business_closed', { when }) : words.text('move.confirm.business_closed_reason', { when, reason: closure.reason });
    }

    if (staff === null) {
        return null;
    }

    return closure.reason === null ? words.text('move.confirm.staff_closed', { staff, when }) : words.text('move.confirm.staff_closed_reason', { staff, when, reason: closure.reason });
}

/**
 * Working hours crossed: the hours that day, the weekday off, the business closed that weekday,
 * or the date taken off. No hours and no reason known for it says the plain line, never a weekday
 * off nobody read.
 */
function hoursLine(facts: CrossingFacts, crossing: NamedCrossing, words: BookingsWords, clock: TenantClock): string {
    const staff = crossing.staff;

    if (facts.day_off === 'business') {
        return words.text('move.confirm.business_weekday', { weekdays: weekdaysOf(facts.date, words) });
    }

    if (staff === null) {
        return crossingLine(crossing, words);
    }

    if (facts.hours.length > 0) {
        const hours = listOf(
            facts.hours.map((window) => words.text('move.confirm.window', { start: formatTimeOfDay(window.start, clock), end: formatTimeOfDay(window.end, clock) })),
            words.language,
        );

        return words.text('move.confirm.outside_hours', { staff, hours });
    }

    if (facts.day_off === 'date') {
        return words.text('move.confirm.off_date', { staff, when: words.text('move.confirm.on_day', { day: dayOf(facts.date, words, clock) }) });
    }

    if (facts.day_off === 'weekday') {
        return words.text('move.confirm.off_weekday', { staff, weekdays: weekdaysOf(facts.date, words) });
    }

    return crossingLine(crossing, words);
}

/** A closure's day or run of days, with its window where it has one. */
function whenOf(closure: CrossingClosure, words: BookingsWords, clock: TenantClock): string {
    const day = dayOf(closure.date, words, clock);
    const window = closure.start !== null && closure.end !== null ? { start: formatTimeOfDay(closure.start, clock), end: formatTimeOfDay(closure.end, clock) } : null;

    if (closure.until === null) {
        return window === null ? words.text('move.confirm.on_day', { day }) : words.text('move.confirm.on_day_window', { day, ...window });
    }

    const until = dayOf(closure.until, words, clock);

    return window === null ? words.text('move.confirm.run', { day, until }) : words.text('move.confirm.run_window', { day, until, ...window });
}

/** A date as a weekday and the date in the tenant's format. */
function dayOf(date: string, words: BookingsWords, clock: TenantClock): string {
    return words.text('move.confirm.day', { weekday: weekdayName(isoWeekday(date), 'long', words.language), date: formatDate(date, clock) });
}

/** The weekday of a date as its plural, with its preposition, in the viewer's language. */
function weekdaysOf(date: string, words: BookingsWords): string {
    const weekday = WEEKDAYS[isoWeekday(date) - 1] ?? 'monday';

    return words.text(`move.confirm.weekdays.${weekday}`);
}
