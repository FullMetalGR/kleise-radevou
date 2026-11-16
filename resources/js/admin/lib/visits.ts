import { router } from '@inertiajs/react';
import { sentenceOf } from '@/lib/sentence';
import type { ShellWords } from '@/lib/strings';

/*
 * A visit whose failure the screen owns: a response that is not a page, or no response at all,
 * is handed to the screen's own handler instead of the framework's modal. The listeners live
 * only while the visit does, from its onStart to its onFinish.
 */
export function owning(onFailure: (event: Event) => void): { onStart: () => void; onFinish: () => void } {
    let release: (() => void)[] = [];

    return {
        onStart: () => {
            const fail = (event: Event) => {
                event.preventDefault();
                onFailure(event);
            };
            release = [router.on('invalid', fail), router.on('exception', fail)];
        },
        onFinish: () => {
            release.forEach((off) => off());
            release = [];
        },
    };
}

/**
 * What a write says where its visit failed and the server worded nothing, a transport failure or
 * a 5xx with no envelope, as an error toast that stays: the one sentence the Billing screen and
 * the console's writes share, a key of the shell's section, read through the strings the screen
 * holds.
 */
export const NOT_DONE = 'toaster.not_done';

/** A closure in a crossing's way: the business's or the member's, its run of dates (until null for one), its window (null for the whole day) and its reason. */
export type CrossingClosure = { business: boolean; date: string; until: string | null; start: string | null; end: string | null; reason: string | null };

/**
 * What is in a crossing's way, as the server read it: the date its service starts on, the member's
 * working windows that date (HH:MM), why there are none (day_off: the date taken off, the business
 * closed that weekday, or the member's own weekday off; null where there are windows), and the
 * closures.
 */
export type CrossingFacts = { date: string; hours: { start: string; end: string }[]; day_off: string | null; closures: CrossingClosure[] };

/**
 * One crossing an unaccepted warning names: the segment, its member, whether hours or a closure,
 * and its facts, null where the answer carried none.
 */
export type Crossing = { segment: number; staff_id: number; kind: string; facts: CrossingFacts | null };

/**
 * segment: the zero-based failing segment a chain conflict names, else null. crossings:
 * what a move's unaccepted warning names, empty for every other refusal. field: the body's field a
 * refused admin write's sentence answers, else null.
 */
export type Failure = { status: number | null; code: string | null; sentence: string | null; segment: number | null; crossings: Crossing[]; field: string | null };

type Envelope = { status?: unknown; data?: unknown };

/** The response a failed visit carried, where the framework's event names one. */
function responseOf(event: Event): Envelope | null {
    const detail: unknown = event instanceof CustomEvent ? event.detail : null;
    const response: unknown = typeof detail === 'object' && detail !== null ? (detail as { response?: unknown }).response : null;

    return typeof response === 'object' && response !== null ? (response as Envelope) : null;
}

/** The crossings a value lists, each a segment, a member, a kind and its facts where it carries them; anything else in it left out. */
export function crossingsOf(value: unknown): Crossing[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.flatMap((item): Crossing[] => {
        const crossing = fieldsOf(item);

        if (typeof crossing.segment !== 'number' || typeof crossing.staff_id !== 'number' || typeof crossing.kind !== 'string') {
            return [];
        }

        return [{ segment: crossing.segment, staff_id: crossing.staff_id, kind: crossing.kind, facts: factsOf(crossing) }];
    });
}

/** A crossing's facts where it names its date, each list read item by item; null where it names none. */
function factsOf(crossing: Record<string, unknown>): CrossingFacts | null {
    if (typeof crossing.date !== 'string') {
        return null;
    }

    const text = (value: unknown): string | null => (typeof value === 'string' ? value : null);
    const hours = (Array.isArray(crossing.hours) ? crossing.hours : []).map(fieldsOf).flatMap((window) => {
        const start = text(window.start);
        const end = text(window.end);

        return start === null || end === null ? [] : [{ start, end }];
    });
    const closures = (Array.isArray(crossing.closures) ? crossing.closures : []).map(fieldsOf).flatMap((closure): CrossingClosure[] => {
        const date = text(closure.date);

        return date === null ? [] : [{ business: closure.business === true, date, until: text(closure.until), start: text(closure.start), end: text(closure.end), reason: text(closure.reason) }];
    });

    return { date: crossing.date, hours, day_off: text(crossing.day_off), closures };
}

/** A value's fields where it is an object, else none. */
function fieldsOf(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

/** A zero-based segment where the value is one, else null. */
function segmentOf(value: unknown): number | null {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null;
}

/** What a failed visit carried: the envelope's status, code and sentence, or nothing for a transport failure. */
function failureOf(event: Event, shell: ShellWords): Failure {
    const response = responseOf(event);

    if (response === null) {
        return { status: null, code: null, sentence: null, segment: null, crossings: [], field: null };
    }

    const envelope = fieldsOf(response.data);
    const carried = fieldsOf(envelope.data);

    return {
        status: typeof response.status === 'number' ? response.status : null,
        code: typeof envelope.code === 'string' ? envelope.code : null,
        sentence: sentenceOf(response.data, shell),
        segment: segmentOf(carried.segment),
        crossings: crossingsOf(carried.crossings),
        field: typeof carried.field === 'string' ? carried.field : null,
    };
}

/**
 * A visit whose failure the screen owns, read as the envelope it carried; the strings are the
 * screen's, which word the sentence where the envelope says none.
 */
export function owningFailure(onFailure: (failure: Failure) => void, shell: ShellWords): { onStart: () => void; onFinish: () => void } {
    return owning((event) => onFailure(failureOf(event, shell)));
}
