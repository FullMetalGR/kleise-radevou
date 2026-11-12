import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { sentenceOf } from '@/lib/sentence';
import { type ShellWords, useShell } from '@/lib/strings';
import { type Crossing, crossingsOf, type Failure, owningFailure } from '@/lib/visits';
import type { MoveChoice } from './MoveConfirm';

/*
 * The move's read as the Move form asks it: GET /admin/api/bookings/{uuid}/move with a
 * tenant-local date and the staff map of the segments that change member, answered with what each
 * segment may be given to and the day's candidate starts, each with the hours and closures it
 * crosses. Advisory, as every availability read is: the move under its locks stays the judge.
 * Asked only while the form is open, and again whenever the day, a member or the round changes;
 * an answer arriving for a query no longer asked is dropped.
 *
 * A refusal is a sentence, the envelope's own, and an OK answer that is not the day's shape is an
 * error with its own sentence rather than a day with no times, as the wizard's read rules.
 *
 * The same read asked for one start is the verdict a drop on the calendar asks before its
 * confirmation opens; and the write both paths post, with its one refusal they answer alike, an
 * unaccepted warning putting the write's crossings into the open confirmation, lives here so it
 * is written once.
 */

export type MoveMember = { id: number; name: string };

/** A segment of the booking as the read answers it: its service, the member it is with now, and who it may be given to. */
export type MoveSegment = { segment: number; service_id: number; service_name: string; staff_id: number; staff_name: string; members: MoveMember[] };

export type Candidate = { start: string; crossings: Crossing[] };

export type MoveDay = { kind: 'idle' } | { kind: 'loading' } | { kind: 'error'; sentence: string } | { kind: 'ready'; segments: MoveSegment[]; starts: Candidate[] };

/** The member a segment changes to, by its position in the chain: the segments that change alone. */
export type StaffMap = Readonly<Record<number, number>>;

/**
 * A member change the confirmation names: the segment it is, its service, the member it leaves and
 * the one it goes to, null where the segment lists no such member (the confirmation's whole line
 * for it).
 */
export type MemberChange = { segment: number; service: string; from: string; to: string | null };

/**
 * A crossing the confirmation names: the service, its member and whether hours or a closure; null
 * for a name the move cannot read.
 */
export type NamedCrossing = { service: string | null; staff: string | null; kind: string };

const IDLE: MoveDay = { kind: 'idle' };

const LOADING: MoveDay = { kind: 'loading' };

/** The read's address for a booking, a day or a start, and the members that change. */
function moveReadUrl(uuid: string, asked: { date: string } | { start: string }, staff: StaffMap): string {
    const query = new URLSearchParams(asked);

    for (const [segment, member] of Object.entries(staff)) {
        query.append(`staff[${segment}]`, String(member));
    }

    return `/admin/api/bookings/${encodeURIComponent(uuid)}/move?${query.toString()}`;
}

function isMember(value: unknown): value is MoveMember {
    const member = typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};

    return typeof member.id === 'number' && typeof member.name === 'string';
}

function segmentOf(value: unknown): MoveSegment | null {
    const segment = typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};

    if (typeof segment.segment !== 'number' || typeof segment.service_name !== 'string' || typeof segment.staff_id !== 'number' || !Array.isArray(segment.members)) {
        return null;
    }

    return {
        segment: segment.segment,
        service_id: typeof segment.service_id === 'number' ? segment.service_id : 0,
        service_name: segment.service_name,
        staff_id: segment.staff_id,
        staff_name: typeof segment.staff_name === 'string' ? segment.staff_name : '',
        members: segment.members.filter(isMember),
    };
}

/** The day's answer as the form reads it, or null where the answer is not that shape. */
function dayOf(answer: unknown): { segments: MoveSegment[]; starts: Candidate[] } | null {
    const day = typeof answer === 'object' && answer !== null ? (answer as Record<string, unknown>) : {};

    if (!Array.isArray(day.segments) || !Array.isArray(day.starts)) {
        return null;
    }

    const segments = day.segments.map(segmentOf).filter((segment): segment is MoveSegment => segment !== null);
    const starts = day.starts
        .filter((start): start is Record<string, unknown> => typeof start === 'object' && start !== null && typeof (start as Record<string, unknown>).start === 'string')
        .map((start) => ({ start: String(start.start), crossings: crossingsOf(start.crossings) }));

    return { segments, starts };
}

/**
 * The day's candidates for a booking, while uuid is non-null; round asks again for the same query,
 * and unreadable is the sentence for an OK answer that is not the day's shape.
 */
export function useMoveDay(uuid: string | null, date: string, staff: StaffMap, round: number, unreadable: string): MoveDay {
    const shell = useShell();
    const [day, setDay] = useState<MoveDay>(IDLE);
    const url = uuid === null || date === '' ? null : moveReadUrl(uuid, { date }, staff);

    useEffect(() => {
        if (url === null) {
            setDay(IDLE);

            return undefined;
        }

        let current = true;
        setDay(LOADING);

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (response): Promise<MoveDay> => {
                const answer: unknown = await response.json().catch(() => null);

                if (!response.ok) {
                    return { kind: 'error', sentence: sentenceOf(answer, shell) };
                }

                const read = dayOf(answer);

                return read === null ? { kind: 'error', sentence: unreadable } : { kind: 'ready', ...read };
            })
            .catch((failure: unknown): MoveDay => ({ kind: 'error', sentence: sentenceOf(failure, shell) }))
            .then((next) => {
                if (current) {
                    setDay(next);
                }
            });

        return () => {
            current = false;
        };
    }, [url, round]);

    return day;
}

/**
 * A member's name on a segment: among those it may be given to, else the one it is with now; null
 * where it names neither.
 */
function memberName(segment: MoveSegment | undefined, id: number): string | null {
    const member = segment?.members.find((candidate) => candidate.id === id);

    if (member !== undefined) {
        return member.name;
    }

    return segment !== undefined && segment.staff_id === id ? segment.staff_name : null;
}

/** The crossings in the confirmation's words: each segment's service and member by name. */
export function namedCrossings(crossings: readonly Crossing[], segments: readonly MoveSegment[]): NamedCrossing[] {
    return crossings.map((crossing) => {
        const segment = segments.find((candidate) => candidate.segment === crossing.segment);

        return { service: segment?.service_name ?? null, staff: memberName(segment, crossing.staff_id), kind: crossing.kind };
    });
}

/** The member changes a staff map makes, in the confirmation's words. */
export function memberChanges(staff: StaffMap, segments: readonly MoveSegment[]): MemberChange[] {
    return segments
        .filter((segment) => staff[segment.segment] !== undefined && staff[segment.segment] !== segment.staff_id)
        .map((segment) => ({ segment: segment.segment, service: segment.service_name, from: segment.staff_name, to: memberName(segment, staff[segment.segment] ?? segment.staff_id) }));
}

/**
 * The verdict on one start: placeable with the segments and the crossings, refused with the
 * server's sentence (the read's own envelope or the verdict's detail), or failed where no answer
 * the screen can read came back, which the screen words itself.
 */
export type Verdict = { kind: 'placeable'; segments: MoveSegment[]; crossings: Crossing[] } | { kind: 'refused'; sentence: string } | { kind: 'failed' };

/** The verdict as the answer carries it, or failed where it is not that shape. */
function verdictOf(answer: unknown, shell: ShellWords): Verdict {
    const read = typeof answer === 'object' && answer !== null ? (answer as Record<string, unknown>) : {};
    const verdict = typeof read.verdict === 'object' && read.verdict !== null ? (read.verdict as Record<string, unknown>) : null;

    if (verdict === null || !Array.isArray(read.segments)) {
        return { kind: 'failed' };
    }

    if (verdict.placeable !== true) {
        return typeof verdict.detail === 'string' && verdict.detail.trim() !== '' ? { kind: 'refused', sentence: verdict.detail } : { kind: 'refused', sentence: sentenceOf(null, shell) };
    }

    return { kind: 'placeable', segments: read.segments.map(segmentOf).filter((segment): segment is MoveSegment => segment !== null), crossings: crossingsOf(verdict.crossings) };
}

/** Asks the read for the verdict on a start and the members that change. */
export async function readVerdict(uuid: string, start: string, staff: StaffMap, shell: ShellWords): Promise<Verdict> {
    try {
        const response = await fetch(moveReadUrl(uuid, { start }, staff), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const answer: unknown = await response.json().catch(() => null);

        if (!response.ok) {
            return answer === null ? { kind: 'failed' } : { kind: 'refused', sentence: sentenceOf(answer, shell) };
        }

        return verdictOf(answer, shell);
    } catch {
        return { kind: 'failed' };
    }
}

/** A move as the write takes it: the booking, the new start and the segments that change member. */
export type MoveTarget = { uuid: string; start: string; staff: StaffMap };

/** What the poster is told: the move landed, or a refusal other than the unaccepted warning. */
export type MoveOutcome = { moved: () => void; refused: (failure: Failure) => void };

export type MoveWrite = {
    busy: boolean;
    /** The crossings the write's unaccepted warning named, which the open confirmation shows in place of the read's; null before one. */
    written: Crossing[] | null;
    /** Forgets the write's crossings, as a confirmation opens afresh. */
    reset: () => void;
    post: (target: MoveTarget, choice: MoveChoice, outcome: MoveOutcome) => void;
};

/**
 * The move's write, one at a time: the start, the segments that change member alone, the warning
 * accepted and the tick, nothing optimistic. An unaccepted warning keeps the confirmation open
 * with the write's crossings, to confirm once more; every other refusal is the poster's to
 * answer.
 */
export function useMoveWrite(): MoveWrite {
    const shell = useShell();
    const [busy, setBusy] = useState(false);
    const [written, setWritten] = useState<Crossing[] | null>(null);

    const post = (target: MoveTarget, choice: MoveChoice, outcome: MoveOutcome) => {
        if (busy) {
            return;
        }

        const owned = owningFailure((refusal) => {
            if (refusal.code === 'unaccepted_warning') {
                setWritten(refusal.crossings);

                return;
            }

            outcome.refused(refusal);
        }, shell);
        const changed = Object.keys(target.staff).length > 0;

        router.post(
            `/admin/bookings/${encodeURIComponent(target.uuid)}/move`,
            { start: target.start, ...(changed ? { staff: { ...target.staff } } : {}), warning_accepted: choice.warningAccepted, tell_customer: choice.tellCustomer },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setBusy(true);
                    owned.onStart();
                },
                onSuccess: outcome.moved,
                onFinish: () => {
                    setBusy(false);
                    owned.onFinish();
                },
            },
        );
    };

    return { busy, written, reset: () => setWritten(null), post };
}
