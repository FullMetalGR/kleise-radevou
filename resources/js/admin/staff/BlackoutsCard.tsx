import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { TimeField } from '@/components/ui/time-field';
import { formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { DEFAULT_WINDOW } from '@/shifts/model';
import type { Blackout } from './model';
import { type StaffKey, type StaffWords, useStaff } from './words';

/*
 * The closures card: the dates on record, each line its dates in mono, a run of consecutive dates
 * the server folded printing its first and last, an all-day closure the dates alone and a partial
 * one the dates and its window, then a muted line beneath, and a Delete at the line's end. Every
 * date and time is spelled in the tenant's formats with no conversion at all, because they carry
 * no instant and converting would shift a closure onto the wrong day.
 *
 * On the Staff list the card is everybody's: given whom Who offers, the muted line names the
 * line's member, or Whole business, beside the reason, and the form opens on Who, Whole business
 * or one member. On a member's page, and on the floor, it is one scope's, and the muted line is
 * the reason alone. The form is then Date and Until, Until optional with its help, Reason with
 * its help (the shell's, shared with the Calendar's closure dialog), Start and End only while
 * Closed all day is unticked, and Closed all day beside Add; Add is disabled while the date is
 * blank. What it adds and removes is the page's to send, matched by dates and window.
 *
 * One action is in flight at a time, and the control that started it is the one that shows busy:
 * the pressed Delete reads Deleting, or Add reads Adding. Every other control is merely disabled
 * meanwhile, and one status sentence beside them says why.
 *
 * Every word is a key of the staff section, read through the screen's words.
 */

/** A line as the card may be handed it: a scope's own, or the list's naming its member, null the whole business. */
type Line = Blackout & { staff_id?: number | null };

/** What the card hands its owner to add: the closure, and whom Who chose, null for the whole business or where Who is not offered. */
export type Draft = Blackout & { staff_id: number | null };

/** Which action the card's owner has in flight, so the card can say which control is busy. */
export type InFlight = { action: 'add' } | { action: 'remove'; blackout: Line } | null;

/** Who, on the Staff list: the members it offers beside Whole business, and the name of every member a line may belong to. */
export type ClosureWho = { offered: readonly { id: number; name: string }[]; names: ReadonlyMap<number, string> };

type Props = {
    title: string;
    blackouts: readonly Line[];
    clock: TenantClock;
    weekStart: number;
    granularity: number;
    inFlight: InFlight;
    onAdd: (draft: Draft) => void;
    onRemove: (blackout: Line) => void;
    idPrefix?: string;
    /** Who on the Staff list; null on one scope's card. */
    who?: ClosureWho | null;
};

const WAIT_REMOVING = 'closures.wait_removing' satisfies StaffKey;

const WAIT_ADDING = 'closures.wait_adding' satisfies StaffKey;

const BUSINESS = 'closures.business' satisfies StaffKey;

/** Who's value for the whole business; a member's is their id. */
const WHOLE = '';

/**
 * The closure form's pieces, shared with the Calendar's closure dialog: the route a scope's
 * closures post to, the business's or one member's; a closure's body as the wire takes it, for
 * the add and for the removal alike; and a typed reason as that body carries it, none where only
 * spaces were typed. Start and End open on the editors' default window (shifts/model).
 */
export function closurePath(staffId: number | null): string {
    return staffId === null ? '/admin/blackouts' : `/admin/staff/${staffId}/blackouts`;
}

export function closureBody(blackout: Blackout): Record<string, string | null> {
    return { date: blackout.date, until: blackout.until, start_time: blackout.start, end_time: blackout.end, reason: blackout.reason };
}

export function typedReason(typed: string): string | null {
    return typed.trim() === '' ? null : typed.trim();
}

/**
 * A closure as the card prints it: its date, or a run's first and last through one line, then
 * its window where it has one, the dates joined to the times with no connector word, each in the
 * tenant's formats.
 */
export function blackoutLabel(blackout: Blackout, clock: TenantClock, words: StaffWords): string {
    const date = blackout.until === null ? formatDate(blackout.date, clock) : words.text('closures.run', { date: formatDate(blackout.date, clock), until: formatDate(blackout.until, clock) });

    return blackout.start !== null && blackout.end !== null ? words.text('closures.window', { date, time: formatTimeOfDay(blackout.start, clock), end: formatTimeOfDay(blackout.end, clock) }) : date;
}

/** The muted line under a list's line: its member or the whole business, and its reason beside where it has one. */
function whoLine(line: Line, who: ClosureWho, words: StaffWords): string {
    const name = line.staff_id === null || line.staff_id === undefined ? words.text(BUSINESS) : (who.names.get(line.staff_id) ?? String(line.staff_id));

    return line.reason === null ? name : words.text('closures.who_reason', { who: name, reason: line.reason });
}

/** What tells one closure from another on the card: its scope, its dates and its window, never a row id. */
function keyOf(blackout: Line): string {
    return `${blackout.staff_id ?? ''}:${blackout.date}:${blackout.until ?? ''}:${blackout.start ?? ''}:${blackout.end ?? ''}`;
}

export function BlackoutsCard({ title, blackouts, clock, weekStart, granularity, inFlight, onAdd, onRemove, idPrefix = 'blackout', who = null }: Props) {
    const [member, setMember] = useState(WHOLE);
    const [date, setDate] = useState('');
    const [until, setUntil] = useState('');
    const [reason, setReason] = useState('');
    const [allDay, setAllDay] = useState(true);
    const [start, setStart] = useState(DEFAULT_WINDOW.start);
    const [end, setEnd] = useState(DEFAULT_WINDOW.end);
    const heading = `${idPrefix}-head`;
    const held = inFlight !== null;
    const words = useStaff();
    const shell = useShell();

    return (
        <section className="admin-blackouts flex flex-col gap-3 rounded-xl border p-4" aria-labelledby={heading}>
            <h2 id={heading} className="font-semibold">
                {title}
            </h2>
            {blackouts.length === 0 ? (
                <p className="admin-blackouts__none text-sm text-muted-foreground">{words.text('closures.none')}</p>
            ) : (
                <ul className="admin-blackouts__list flex flex-col">
                    {blackouts.map((blackout) => (
                        <li key={keyOf(blackout)} className="admin-blackouts__row flex items-center justify-between gap-3 border-t py-2 text-sm first:border-t-0">
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="admin-blackouts__when font-mono">{blackoutLabel(blackout, clock, words)}</span>
                                {who !== null ? (
                                    <span className="admin-blackouts__who text-muted-foreground">{whoLine(blackout, who, words)}</span>
                                ) : (
                                    blackout.reason !== null && <span className="admin-blackouts__reason text-muted-foreground">{blackout.reason}</span>
                                )}
                            </span>
                            <Button
                                variant="link"
                                destructive
                                className="admin-blackouts__delete"
                                disabled={held}
                                busy={inFlight?.action === 'remove' && keyOf(inFlight.blackout) === keyOf(blackout)}
                                busyLabel={words.text('closures.deleting')}
                                onClick={() => onRemove(blackout)}
                            >
                                {words.text('closures.delete')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {inFlight !== null && (
                <p className="admin-blackouts__wait text-sm text-muted-foreground" role="status">
                    {words.text(inFlight.action === 'add' ? WAIT_ADDING : WAIT_REMOVING)}
                </p>
            )}
            <form
                className="admin-blackouts__form grid gap-3 border-t pt-3 desktop:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (date !== '' && !held) {
                        onAdd({
                            staff_id: who === null || member === WHOLE ? null : Number(member),
                            date,
                            until: until === '' ? null : until,
                            start: allDay ? null : start,
                            end: allDay ? null : end,
                            reason: typedReason(reason),
                        });
                    }
                }}
            >
                {who !== null && (
                    <>
                        <Field id={`${idPrefix}-who`} label={words.text('closures.who')}>
                            {(control) => (
                                <Select
                                    {...control}
                                    className="admin-blackouts__who-select"
                                    options={[{ value: WHOLE, label: words.text(BUSINESS) }, ...who.offered.map((offered) => ({ value: String(offered.id), label: offered.name }))]}
                                    value={member}
                                    onChange={setMember}
                                />
                            )}
                        </Field>
                        <div className="max-desktop:hidden" />
                    </>
                )}
                <Field id={`${idPrefix}-date`} label={words.text('closures.date')}>
                    {(control) => <DateField {...control} className="admin-blackouts__date" value={date} onChange={setDate} clock={clock} weekStart={weekStart} />}
                </Field>
                <Field id={`${idPrefix}-until`} label={words.text('closures.until')} help={words.text('closures.until_help')}>
                    {(control) => <DateField {...control} className="admin-blackouts__until" value={until} onChange={setUntil} clock={clock} weekStart={weekStart} />}
                </Field>
                <Field id={`${idPrefix}-reason`} label={words.text('closures.reason')} help={shell.text('shared.closure.reason_help')} className="desktop:col-span-2">
                    {(control) => <TextInput {...control} className="admin-blackouts__reason-input" value={reason} onChange={(event) => setReason(event.target.value)} />}
                </Field>
                {!allDay && (
                    <>
                        <Field id={`${idPrefix}-start`} label={words.text('closures.start')}>
                            {(control) => <TimeField {...control} className="admin-blackouts__start" value={start} onChange={setStart} clock={clock} granularity={granularity} />}
                        </Field>
                        <Field id={`${idPrefix}-end`} label={words.text('closures.end')}>
                            {(control) => <TimeField {...control} className="admin-blackouts__end" value={end} onChange={setEnd} clock={clock} granularity={granularity} />}
                        </Field>
                    </>
                )}
                <div className="admin-blackouts__all-day flex items-center gap-2 text-sm">
                    <input type="checkbox" id={`${idPrefix}-all-day`} className="admin-blackouts__all-day-tick size-4 accent-primary" checked={allDay} onChange={(event) => setAllDay(event.target.checked)} />
                    <label htmlFor={`${idPrefix}-all-day`}>{words.text('closures.all_day')}</label>
                </div>
                <div className="flex justify-end">
                    <Button type="submit" variant="primary" className="admin-blackouts__add" disabled={date === '' || held} busy={inFlight?.action === 'add'} busyLabel={words.text('closures.adding')}>
                        {words.text('closures.add')}
                    </Button>
                </div>
            </form>
        </section>
    );
}
