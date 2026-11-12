import { useEffect, useState } from 'react';
import { wallClockOf } from '../../booking/time';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field } from '@/components/ui/field';
import { Notice } from '@/components/ui/notice';
import { Select } from '@/components/ui/select';
import { formatInstant, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import type { Crossing } from '@/lib/visits';
import { sentenceFor } from './acting';
import { type Booking, distinct, startOf } from './model';
import { MoveConfirm, type MoveChoice } from './MoveConfirm';
import { type Candidate, memberChanges, type MoveDay, type MoveSegment, namedCrossings, type StaffMap, useMoveDay, useMoveWrite } from './moving';
import { type BookingsKey, type BookingsWords, useBookings } from './words';

/*
 * The Move form inside the booking dialog: the dialog's body turns into the form and its footer
 * into Back and Save, so the move is no second modal. A line names what moves and where it sits
 * now; Date defaults to the booking's day in the tenant's zone; a Staff member select per
 * segment, labelled by its service where the booking has more than one, where the dialog shows
 * staff and the segment has more than one member to give it to; and Time, the day's candidates
 * from the move's read in the tenant's clock, each that crosses the member's hours or a closure
 * marked so in its own words. A change of the day or a member reads again; a time still offered
 * stays chosen, any other clears. Save waits for a time and says so beside it; Back returns to
 * the detail and moves nothing.
 *
 * Save opens the confirmation (MoveConfirm) over the dialog, and its confirm posts the move: the
 * start, the segments that change member, the warning accepted exactly when the confirmation
 * showed it, and whether to tell the customer. Nothing is optimistic: the confirm is busy until
 * the answer lands, and a move that lands returns the dialog to the detail the redirect re-reads.
 * A refusal is read by its code: an unaccepted warning, the day having changed since the read,
 * puts the write's crossings into the open confirmation to confirm once more; another booking in
 * the way or a start the planner no longer takes closes it, says the server's sentence in the
 * form and reads the times again; anything else closes it and says the sentence where the dialog
 * says a failed action.
 */

/** The refusals the form answers by reading the times again. */
const REREAD = new Set(['overlap', 'unavailable']);

/** The time of a start in the tenant's clock, spelled in the tenant's time format. */
function timeOf(start: string, clock: TenantClock): string {
    try {
        const at = wallClockOf(start, clock.timezone);

        return formatTimeOfDay(`${String(at.hour).padStart(2, '0')}:${String(at.minute).padStart(2, '0')}`, clock);
    } catch {
        return start;
    }
}

/** A candidate's line in the Time list: its time, marked by what it crosses. */
function labelOf(candidate: Candidate, clock: TenantClock, words: BookingsWords): string {
    const time = timeOf(candidate.start, clock);
    const kinds = new Set(candidate.crossings.map((crossing) => crossing.kind));
    const hours = kinds.has('hours');
    const closure = kinds.has('closure');

    if (!hours && !closure) {
        return time;
    }

    const key: BookingsKey = hours && closure ? 'move.time_both' : closure ? 'move.time_closure' : 'move.time_hours';

    return words.text(key, { time });
}

/**
 * The Time list's two groups, each in the read's time order: first the times that cross nothing,
 * then those outside working hours or on a closure, so the free times are not a day's worth of
 * marked rows down; the select leaves an empty group out.
 */
function groupsOf(starts: readonly Candidate[], clock: TenantClock, words: BookingsWords) {
    const option = (candidate: Candidate) => ({ value: candidate.start, label: labelOf(candidate, clock, words) });

    return [
        { label: words.text('move.group_free'), options: starts.filter((candidate) => candidate.crossings.length === 0).map(option) },
        { label: words.text('move.group_marked'), options: starts.filter((candidate) => candidate.crossings.length > 0).map(option) },
    ];
}

/**
 * The members a segment's select lists: the read's, with the member the segment is with now put
 * first where the read leaves them out (the implicit member, or one no longer active), so the
 * select shows the segment as it stands and every member listed can be chosen.
 */
function choicesOf(segment: MoveSegment): MoveSegment['members'] {
    return segment.members.some((member) => member.id === segment.staff_id) ? segment.members : [{ id: segment.staff_id, name: segment.staff_name }, ...segment.members];
}

/** The booking's day in the tenant's zone. */
function dayOf(booking: Booking, clock: TenantClock): string {
    try {
        return wallClockOf(startOf(booking), clock.timezone).date;
    } catch {
        return '';
    }
}

type Input = { booking: Booking | null; clock: TenantClock };

export type MoveForm = {
    /** Whether the dialog shows the form in place of the detail. */
    moving: boolean;
    begin: () => void;
    back: () => void;
    /** A refusal said where the dialog says a failed action, or null. */
    failure: string | null;
    date: string;
    setDate: (date: string) => void;
    staff: StaffMap;
    setMember: (segment: MoveSegment, id: number) => void;
    time: string;
    setTime: (time: string) => void;
    day: MoveDay;
    segments: MoveSegment[];
    /** The sentence a refused move said in the form, or null. */
    refused: string | null;
    saveable: boolean;
    confirming: boolean;
    save: () => void;
    dismiss: () => void;
    shown: Crossing[];
    busy: boolean;
    confirm: (choice: MoveChoice) => void;
};

export function useMoveForm({ booking, clock }: Input): MoveForm {
    const words = useBookings();
    const write = useMoveWrite();
    const [moving, setMoving] = useState(false);
    const [date, setDateState] = useState('');
    const [staff, setStaff] = useState<StaffMap>({});
    const [time, setTimeState] = useState('');
    const [round, setRound] = useState(0);
    const [segments, setSegments] = useState<MoveSegment[]>([]);
    const [refused, setRefused] = useState<string | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const busy = write.busy;
    const uuid = moving && booking !== null ? booking.uuid : null;
    const day = useMoveDay(uuid, date, staff, round, words.text('move.unreadable'));
    const offered = day.kind === 'ready' ? day.starts.find((candidate) => candidate.start === time) : undefined;

    useEffect(() => {
        if (day.kind !== 'ready') {
            return;
        }

        setSegments(day.segments);

        if (time !== '' && !day.starts.some((candidate) => candidate.start === time)) {
            setTimeState('');
        }
    }, [day]);

    const begin = () => {
        if (booking === null) {
            return;
        }

        setDateState(dayOf(booking, clock));
        setStaff({});
        setTimeState('');
        setSegments([]);
        setRefused(null);
        setFailure(null);
        setConfirming(false);
        setMoving(true);
    };

    const setMember = (segment: MoveSegment, id: number) => {
        const next: Record<number, number> = { ...staff };

        if (id === segment.staff_id) {
            delete next[segment.segment];
        } else {
            next[segment.segment] = id;
        }

        setRefused(null);
        setStaff(next);
    };

    const confirm = (choice: MoveChoice) => {
        if (booking === null || offered === undefined || busy) {
            return;
        }

        setFailure(null);
        write.post({ uuid: booking.uuid, start: offered.start, staff }, choice, {
            moved: () => {
                setConfirming(false);
                setMoving(false);
            },
            refused: (refusal) => {
                setConfirming(false);

                if (refusal.code !== null && REREAD.has(refusal.code)) {
                    setRefused(refusal.sentence ?? sentenceFor(refusal, words));
                    setRound((value) => value + 1);

                    return;
                }

                setFailure(sentenceFor(refusal, words));
            },
        });
    };

    return {
        moving: moving && booking !== null,
        begin,
        back: () => {
            setFailure(null);
            setRefused(null);
            setMoving(false);
        },
        failure,
        date,
        setDate: (next) => {
            if (next !== '') {
                setRefused(null);
                setDateState(next);
            }
        },
        staff,
        setMember,
        time,
        setTime: (next) => {
            setRefused(null);
            setTimeState(next);
        },
        day,
        segments,
        refused,
        saveable: offered !== undefined && !busy,
        confirming,
        save: () => {
            if (offered !== undefined) {
                write.reset();
                setConfirming(true);
            }
        },
        dismiss: () => setConfirming(false),
        shown: write.written ?? offered?.crossings ?? [],
        busy,
        confirm,
    };
}

type Props = { form: MoveForm; booking: Booking; staffShown: boolean; clock: TenantClock; weekStart: number };

/** The form's body: the line, Date, the member selects, Time and its states, and the confirmation over it. */
export function MoveFields({ form, booking, staffShown, clock, weekStart }: Props) {
    const words = useBookings();
    const services = listOf(distinct(booking.items.map((item) => item.service_name)), words.language);
    const when = formatInstant(startOf(booking), clock);
    const now = staffShown ? words.text('move.now', { services, when, staff: listOf(distinct(booking.items.map((item) => item.staff_name)), words.language) }) : words.text('move.now_alone', { services, when });
    const choosing = staffShown ? form.segments.filter((segment) => choicesOf(segment).length > 1) : [];
    const { day } = form;

    return (
        <div className="admin-move flex flex-col gap-4">
            <p className="admin-move__now text-sm">{now}</p>
            <Field id="move-date" label={words.text('move.date')}>
                {(control) => <DateField {...control} className="admin-move__date" value={form.date} onChange={form.setDate} clock={clock} weekStart={weekStart} />}
            </Field>
            {choosing.map((segment) => (
                <Field key={segment.segment} id={`move-staff-${String(segment.segment)}`} label={form.segments.length > 1 ? words.text('move.staff_for', { service: segment.service_name }) : words.text('move.staff')}>
                    {(control) => (
                        <Select
                            {...control}
                            className="admin-move__staff w-full"
                            options={choicesOf(segment).map((member) => ({ value: String(member.id), label: member.name }))}
                            value={String(form.staff[segment.segment] ?? segment.staff_id)}
                            onChange={(value) => form.setMember(segment, Number(value))}
                        />
                    )}
                </Field>
            ))}
            {form.refused !== null && (
                <Notice kind="error" className="admin-move__refused">
                    {form.refused}
                </Notice>
            )}
            {day.kind === 'ready' && day.starts.length > 0 ? (
                <Field id="move-time" label={words.text('move.time')} help={words.text('move.time_help')}>
                    {(control) => (
                        <Select
                            {...control}
                            className="admin-move__time w-full"
                            options={[{ value: '', label: words.text('move.choose') }]}
                            groups={groupsOf(day.starts, clock, words)}
                            value={form.time}
                            onChange={form.setTime}
                        />
                    )}
                </Field>
            ) : (
                <div className="admin-field flex flex-col gap-1">
                    <span className="admin-field__label text-sm font-medium">{words.text('move.time')}</span>
                    {day.kind === 'error' ? (
                        <p role="alert" className="admin-move__error text-sm text-destructive">
                            {day.sentence}
                        </p>
                    ) : day.kind === 'ready' ? (
                        <p role="status" className="admin-move__none text-sm text-muted-foreground">
                            {words.text('move.none')}
                        </p>
                    ) : (
                        <p role="status" className="admin-move__loading text-sm text-muted-foreground">
                            {words.text('move.loading')}
                        </p>
                    )}
                </div>
            )}
            <MoveConfirm
                open={form.confirming}
                booking={booking}
                to={form.time}
                changes={memberChanges(form.staff, form.segments)}
                crossings={namedCrossings(form.shown, form.segments)}
                clock={clock}
                busy={form.busy}
                onConfirm={form.confirm}
                onDismiss={form.dismiss}
            />
        </div>
    );
}

const WHY = 'move-save-why';

/** The form's footer: why Save waits, Back and Save. */
export function MoveFooter({ form }: { form: MoveForm }) {
    const words = useBookings();

    return (
        <>
            {!form.saveable && !form.busy && (
                <p id={WHY} className="admin-move__why mr-auto self-center text-sm text-muted-foreground">
                    {words.text('move.save_why')}
                </p>
            )}
            <Button className="admin-move__back" disabled={form.busy} onClick={form.back}>
                {words.text('move.back')}
            </Button>
            <Button variant="primary" className="admin-move__save" disabled={!form.saveable} aria-describedby={!form.saveable && !form.busy ? WHY : undefined} onClick={form.save}>
                {words.text('move.save')}
            </Button>
        </>
    );
}
