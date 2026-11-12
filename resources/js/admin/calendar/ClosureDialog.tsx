import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { TimeField } from '@/components/ui/time-field';
import type { TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { owningFailure } from '@/lib/visits';
import { DEFAULT_WINDOW } from '@/shifts/model';
import { closureBody, closurePath, typedReason } from '@/staff/BlackoutsCard';
import { useToasts } from '@/toast/toasts';
import type { Member } from './model';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * The Calendar's closure dialog: New closure, one date per closure, posted to the blackout routes
 * the Staff page's cards post to, with the route, the body and the defaults those cards use
 * (staff/BlackoutsCard). Who is the whole business, or a member of the window's members where the
 * tenant has the staff feature; a tenant without it closes the business alone. Closed all day is
 * ticked by default; unticked, Start and End.
 *
 * Add shows busy while the write is in flight and nothing is optimistic: the server's flash
 * says it landed, the redirect back lands the page's props again and the Calendar re-reads the
 * window it wants, so the closure arrives in the members' hours, and the dialog closes. A refusal
 * is the envelope's sentence as an error toast, the dialog kept open with what was typed.
 *
 * A prefill names the member and the date the dialog opens on, for the Day column's menu. Every
 * word is a key of the calendar section.
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
};

export const CLOSURE_TITLE: CalendarKey = 'closure.title';

export const WHOLE_BUSINESS: CalendarKey = 'closure.whole_business';

/** The toast where the write failed and the server worded nothing. */
const CLOSURE_NOT_ADDED: CalendarKey = 'closure.not_added';

const BUSINESS = '';

export function ClosureDialog({ onClose, members, clock, weekStart, granularity, prefill }: Props) {
    const shell = useShell();
    const words = useCalendarWords();
    const toasts = useToasts();
    const named = prefill?.staffId ?? null;
    const offered = named !== null && members.some((member) => member.id === named) ? String(named) : BUSINESS;
    const [who, setWho] = useState(offered);
    const [date, setDate] = useState(prefill?.date ?? '');
    const [reason, setReason] = useState('');
    const [allDay, setAllDay] = useState(true);
    const [start, setStart] = useState(DEFAULT_WINDOW.start);
    const [end, setEnd] = useState(DEFAULT_WINDOW.end);
    const [busy, setBusy] = useState(false);

    const add = () => {
        if (date === '' || busy) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? words.text(CLOSURE_NOT_ADDED)), shell);
        const body = closureBody({ date, until: null, start: allDay ? null : start, end: allDay ? null : end, reason: typedReason(reason) });

        router.post(closurePath(who === BUSINESS ? null : Number(who)), body, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                setBusy(true);
                owned.onStart();
            },
            onSuccess: () => onClose(),
            onFinish: () => {
                setBusy(false);
                owned.onFinish();
            },
        });
    };

    const footer = (
        <div className="flex justify-end gap-2">
            <Button className="admin-closure__cancel" onClick={onClose} disabled={busy}>
                {words.text('closure.cancel')}
            </Button>
            <Button variant="primary" className="admin-closure__add" disabled={date === ''} busy={busy} busyLabel={words.text('closure.adding')} onClick={add}>
                {words.text('closure.add')}
            </Button>
        </div>
    );

    return (
        <Modal open title={words.text(CLOSURE_TITLE)} onClose={onClose} footer={footer} className="admin-closure max-w-lg">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Field id="closure-who" label={words.text('closure.who')}>
                        {(control) => (
                            <Select
                                {...control}
                                className="admin-closure__who w-full"
                                options={[{ value: BUSINESS, label: words.text(WHOLE_BUSINESS) }, ...members.map((member) => ({ value: String(member.id), label: member.name }))]}
                                value={who}
                                onChange={setWho}
                            />
                        )}
                    </Field>
                </div>
                <Field id="closure-date" label={words.text('closure.date')}>
                    {(control) => <DateField {...control} className="admin-closure__date" value={date} onChange={setDate} clock={clock} weekStart={weekStart} />}
                </Field>
                <Field id="closure-reason" label={words.text('closure.reason')} help={shell.text('shared.closure.reason_help')}>
                    {(control) => <TextInput {...control} className="admin-closure__reason" value={reason} onChange={(event) => setReason(event.target.value)} />}
                </Field>
                <div className="flex items-center gap-2 sm:col-span-2">
                    <input type="checkbox" id="closure-all-day" className="admin-closure__all-day" checked={allDay} onChange={(event) => setAllDay(event.target.checked)} />
                    <label htmlFor="closure-all-day">{words.text('closure.all_day')}</label>
                </div>
                {!allDay && (
                    <>
                        <Field id="closure-start" label={words.text('closure.start')}>
                            {(control) => <TimeField {...control} className="admin-closure__start" value={start} onChange={setStart} clock={clock} granularity={granularity} />}
                        </Field>
                        <Field id="closure-end" label={words.text('closure.end')}>
                            {(control) => <TimeField {...control} className="admin-closure__end" value={end} onChange={setEnd} clock={clock} granularity={granularity} />}
                        </Field>
                    </>
                )}
            </div>
        </Modal>
    );
}
