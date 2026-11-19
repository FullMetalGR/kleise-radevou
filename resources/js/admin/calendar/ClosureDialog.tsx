import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field, useFocusFirstFailing } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { TimeField } from '@/components/ui/time-field';
import type { TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { DEFAULT_WINDOW } from '@/shifts/model';
import { closureBody, closurePath, type Draft, typedReason } from '@/staff/BlackoutsCard';
import { BookingsToMove, InsideChoices, InsideList, useClosureAdd } from '@/staff/BookingsInside';
import { useToasts } from '@/toast/toasts';
import type { Member } from './model';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * The Calendar's closure dialog: New closure, a date and an optional Until, the last day of a
 * closure over several days, posted to the blackout routes the Staff page's cards post to, with
 * the route, the body and the defaults those cards use (staff/BlackoutsCard). Blank Until is the
 * one date; the server writes one closure for each day of a range and refuses an Until before the
 * date or past its longest run. Who is the whole business, or a member of the window's members
 * where the tenant has the staff feature; a tenant without it closes the business alone. Closed
 * all day is ticked by default; unticked, Start and End, the same hours on every day of the range.
 *
 * Add shows busy while the write is in flight and nothing is optimistic: the server's flash
 * says it landed, the redirect back lands the page's props again and the Calendar re-reads the
 * window it wants, so the closure arrives in the members' hours, and the dialog closes. While the
 * add or an answer is in flight the dialog stays open, whatever asks it to close: Escape and a
 * press on the backdrop do nothing, as Cancel and Back are disabled. A refusal is the envelope's
 * sentence as an error toast, the dialog kept open with what was typed; a refusal of Until shows
 * its sentence under Until instead, until Date or Until changes, and moves focus to Until, so its
 * sentence is announced as the field takes focus.
 *
 * Where bookings fall inside the closure, Add writes nothing and the dialog's body turns into
 * their list and its footer into the answers and Back (staff/BookingsInside), the same dialog with
 * what was typed kept behind it; Back returns to the fields as typed. After Add and move them
 * lands, the dialog becomes the list of bookings to move, and its Done closes it.
 *
 * A prefill names the member and the date the dialog opens on, for the Day column's menu; Until
 * opens blank. Every word is a key of the calendar section, or of the shell's for the list.
 */

/** What the dialog opens on: a member by id (null or absent, the whole business) and a date. */
type ClosurePrefill = { staffId?: number | null; date?: string };

type Props = {
    onClose: () => void;
    /** The members the dialog offers, the window's; none where the tenant has no staff feature. */
    members: readonly Member[];
    clock: TenantClock;
    weekStart: number;
    granularity: number;
    prefill?: ClosurePrefill;
    /** Whether the screen shows members, so the list of bookings names each one's. */
    staffShown?: boolean;
};

export const CLOSURE_TITLE: CalendarKey = 'closure.title';

export const WHOLE_BUSINESS: CalendarKey = 'closure.whole_business';

/** The toast where the write failed and the server worded nothing. */
const CLOSURE_NOT_ADDED: CalendarKey = 'closure.not_added';

const BUSINESS = '';

/** The body's field a refusal of Until names. */
const UNTIL = 'until';

/** What Escape and a backdrop press do while a write is in flight: nothing, as Cancel is disabled. */
function nothing(): void {}

/** What the fields hold, kept while the list of bookings stands in their place. who: a member's id as the select holds it, or the whole business. */
type Typed = { who: string; date: string; until: string; reason: string; allDay: boolean; start: string; end: string };

/** The fields as the dialog opens: the prefill's member where the window offers them, else the whole business, its date, and the cards' defaults for the rest. */
function opening(prefill: ClosurePrefill | undefined, members: readonly Member[]): Typed {
    const named = prefill?.staffId ?? null;
    const offered = named !== null && members.some((member) => member.id === named);

    return { who: offered ? String(named) : BUSINESS, date: prefill?.date ?? '', until: '', reason: '', allDay: true, start: DEFAULT_WINDOW.start, end: DEFAULT_WINDOW.end };
}

/** The closure the fields say, as the cards hand one over: blank Until the one date, no window while Closed all day is ticked, the reason as typed or none. */
function draftOf(typed: Typed): Draft {
    return {
        staff_id: typed.who === BUSINESS ? null : Number(typed.who),
        date: typed.date,
        until: typed.until === '' ? null : typed.until,
        start: typed.allDay ? null : typed.start,
        end: typed.allDay ? null : typed.end,
        reason: typedReason(typed.reason),
    };
}

type FieldsProps = {
    typed: Typed;
    /** A change to any field but the two dates. */
    onChange: (next: Partial<Typed>) => void;
    /** A change to Date or Until. */
    onDates: (next: Partial<Typed>) => void;
    /** Until's refusal, and how many there have been: each new one moves focus to Until. */
    untilError: string | null;
    refusals: number;
    members: readonly Member[];
    clock: TenantClock;
    weekStart: number;
    granularity: number;
};

/** The dialog's fields: Who, Date and Until, Reason, Closed all day, and Start and End while it is unticked. */
function ClosureFields({ typed, onChange, onDates, untilError, refusals, members, clock, weekStart, granularity }: FieldsProps) {
    const shell = useShell();
    const words = useCalendarWords();
    const fields = useFocusFirstFailing<HTMLDivElement>(refusals);

    return (
        <div ref={fields} className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <Field id="closure-who" label={words.text('closure.who')}>
                    {(control) => (
                        <Select
                            {...control}
                            className="admin-closure__who w-full"
                            options={[
                                {
                                    value: BUSINESS,
                                    label: words.text(WHOLE_BUSINESS),
                                },
                                ...members.map((member) => ({
                                    value: String(member.id),
                                    label: member.name,
                                })),
                            ]}
                            value={typed.who}
                            onChange={(who) => onChange({ who })}
                        />
                    )}
                </Field>
            </div>
            <Field id="closure-date" label={words.text('closure.date')}>
                {(control) => <DateField {...control} className="admin-closure__date" value={typed.date} onChange={(date) => onDates({ date })} clock={clock} weekStart={weekStart} />}
            </Field>
            <Field id="closure-until" label={words.text('closure.until')} help={words.text('closure.until_help')} error={untilError}>
                {(control) => <DateField {...control} className="admin-closure__until" value={typed.until} onChange={(until) => onDates({ until })} clock={clock} weekStart={weekStart} />}
            </Field>
            <Field id="closure-reason" label={words.text('closure.reason')} help={shell.text('shared.closure.reason_help')} className="sm:col-span-2">
                {(control) => <TextInput {...control} className="admin-closure__reason" value={typed.reason} onChange={(event) => onChange({ reason: event.target.value })} />}
            </Field>
            <div className="flex items-center gap-2 sm:col-span-2">
                <input type="checkbox" id="closure-all-day" className="admin-closure__all-day" checked={typed.allDay} onChange={(event) => onChange({ allDay: event.target.checked })} />
                <label htmlFor="closure-all-day">{words.text('closure.all_day')}</label>
            </div>
            {!typed.allDay && (
                <>
                    <Field id="closure-start" label={words.text('closure.start')}>
                        {(control) => <TimeField {...control} className="admin-closure__start" value={typed.start} onChange={(start) => onChange({ start })} clock={clock} granularity={granularity} />}
                    </Field>
                    <Field id="closure-end" label={words.text('closure.end')}>
                        {(control) => <TimeField {...control} className="admin-closure__end" value={typed.end} onChange={(end) => onChange({ end })} clock={clock} granularity={granularity} />}
                    </Field>
                </>
            )}
        </div>
    );
}

export function ClosureDialog({ onClose, members, clock, weekStart, granularity, prefill, staffShown = false }: Props) {
    const words = useCalendarWords();
    const toasts = useToasts();
    const [typed, setTyped] = useState(() => opening(prefill, members));
    const [untilError, setUntilError] = useState<string | null>(null);
    const [refusals, setRefusals] = useState(0);
    const adding = useClosureAdd((failure) => {
        if (failure.field === UNTIL && failure.sentence !== null) {
            setUntilError(failure.sentence);
            setRefusals((count) => count + 1);
        } else {
            toasts.push('error', failure.sentence ?? words.text(CLOSURE_NOT_ADDED));
        }
    });
    const busy = adding.pressed !== null;
    const draft = draftOf(typed);

    const change = (next: Partial<Typed>) => setTyped((was) => ({ ...was, ...next }));

    /** A change to Date or Until, which clears Until's refusal: the dates it answered are gone. */
    const redate = (next: Partial<Typed>) => {
        change(next);
        setUntilError(null);
    };

    const add = () => {
        if (draft.date === '') {
            return;
        }

        // The dialog stays open as the list of bookings to move; any other landing closes it.
        adding.add(closurePath(draft.staff_id), closureBody(draft), (toMove) => {
            if (!toMove) {
                onClose();
            }
        });
    };

    if (adding.toMove !== null) {
        return <BookingsToMove rows={adding.toMove} staffShown={staffShown} clock={clock} weekStart={weekStart} onDone={onClose} />;
    }

    const close = busy ? nothing : onClose;

    // The list step is the same dialog, its place and its type kept, so that it stays the one that was opened.
    if (adding.asked !== null) {
        return (
            <Modal open title={words.text(CLOSURE_TITLE)} onClose={close} footer={<InsideChoices adding={adding} />} className="admin-closure max-w-lg">
                <InsideList asked={adding.asked} staffShown={staffShown} clock={clock} reasonTyped={draft.reason !== null} />
            </Modal>
        );
    }

    const footer = (
        <div className="flex flex-wrap justify-end gap-2">
            <Button className="admin-closure__cancel" onClick={onClose} disabled={busy}>
                {words.text('closure.cancel')}
            </Button>
            <Button variant="primary" className="admin-closure__add" disabled={draft.date === ''} busy={busy} busyLabel={words.text('closure.adding')} onClick={add}>
                {words.text('closure.add')}
            </Button>
        </div>
    );

    return (
        <Modal open title={words.text(CLOSURE_TITLE)} onClose={close} footer={footer} className="admin-closure max-w-lg">
            <ClosureFields typed={typed} onChange={change} onDates={redate} untilError={untilError} refusals={refusals} members={members} clock={clock} weekStart={weekStart} granularity={granularity} />
        </Modal>
    );
}
