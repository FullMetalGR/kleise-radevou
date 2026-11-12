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

/** One crossing an unaccepted warning names: the segment, its member and whether hours or a closure. */
export type Crossing = { segment: number; staff_id: number; kind: string };

/**
 * segment: the zero-based failing segment a chain conflict names, else null. crossings:
 * what a move's unaccepted warning names, empty for every other refusal.
 */
export type Failure = { status: number | null; code: string | null; sentence: string | null; segment: number | null; crossings: Crossing[] };

type Envelope = { status?: unknown; data?: unknown };

/** The response a failed visit carried, where the framework's event names one. */
function responseOf(event: Event): Envelope | null {
    const detail: unknown = event instanceof CustomEvent ? event.detail : null;
    const response: unknown = typeof detail === 'object' && detail !== null ? (detail as { response?: unknown }).response : null;

    return typeof response === 'object' && response !== null ? (response as Envelope) : null;
}

/** The crossings a value lists, each a segment, a member and a kind; anything else in it left out. */
export function crossingsOf(value: unknown): Crossing[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.filter((item): item is Crossing => {
        const crossing = fieldsOf(item);

        return typeof crossing.segment === 'number' && typeof crossing.staff_id === 'number' && typeof crossing.kind === 'string';
    });
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
        return { status: null, code: null, sentence: null, segment: null, crossings: [] };
    }

    const envelope = fieldsOf(response.data);
    const carried = fieldsOf(envelope.data);

    return {
        status: typeof response.status === 'number' ? response.status : null,
        code: typeof envelope.code === 'string' ? envelope.code : null,
        sentence: sentenceOf(response.data, shell),
        segment: segmentOf(carried.segment),
        crossings: crossingsOf(carried.crossings),
    };
}

/**
 * A visit whose failure the screen owns, read as the envelope it carried; the strings are the
 * screen's, which word the sentence where the envelope says none.
 */
export function owningFailure(onFailure: (failure: Failure) => void, shell: ShellWords): { onStart: () => void; onFinish: () => void } {
    return owning((event) => onFailure(failureOf(event, shell)));
}
