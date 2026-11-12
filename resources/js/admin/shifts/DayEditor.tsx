import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Modal } from '@/components/ui/modal';
import { TimeField } from '@/components/ui/time-field';
import { isoWeekday } from '@/lib/day';
import { formatDate, formatTimeOfDay, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { useShell } from '@/lib/strings';
import { DEFAULT_WINDOW, type ShiftMember, type ShiftSource, type ShiftWindow, windowAfter, windowMinutes } from './model';
import { useShiftWrite } from './useShiftWrite';
import { hoursLine, MOST_WINDOWS, type ShiftWords } from './words';

/*
 * The Shifts day editor: one member's one date, titled "<name>, <weekday> <date>", the weekday
 * ICU's long name. The day's windows as Start and End time fields stepped at the tenant's
 * granularity, each with Remove as a destructive text action, and Add a window, outlined, up to
 * two; the line of the hours they cover and of what the pattern gives that date. The foot holds Not
 * working this day as a destructive text action at its start, then Back to the pattern, Cancel and
 * Save. Save puts the windows as that date's override, Not working this day puts an empty one, and
 * Back to the pattern, shown where the day is an override, deletes it (replace-all for the date).
 *
 * Nothing is optimistic: the action pressed shows busy and the others wait, Cancel, Escape and a
 * backdrop press among them, so the editor stays until the answer arrives; the server's flash
 * says it landed and the redirect back answers the page's props again, so the grid redraws from
 * the server, and the editor closes. A refusal (the ordered and overlap sentences of shifts.php
 * among them) is the envelope's sentence as an error toast, the editor kept open with what was
 * typed.
 *
 * The editor takes the member, the date, the day's windows and source and the windows its pattern
 * gives that date as props, so the Calendar's Day column menu mounts it too; its words are the
 * shifts section, which the mounting screen's controller names.
 */

type Props = {
    member: ShiftMember;
    date: string;
    windows: readonly ShiftWindow[];
    source: ShiftSource;
    /** What the pattern gives that date, the override left out: the server's resolver read. */
    patternWindows: readonly ShiftWindow[];
    clock: TenantClock;
    granularity: number;
    words: ShiftWords;
    onClose: () => void;
};

type Action = 'save' | 'off' | 'back';

/** What Escape and a backdrop press do while a write is in flight: nothing, as Cancel is disabled. */
function nothing(): void {}

function patternLine(windows: readonly ShiftWindow[], words: ShiftWords, clock: TenantClock): string {
    if (windows.length === 0) {
        return words.text('editor.pattern_none');
    }

    return words.text('editor.pattern_gives', { windows: windows.map((window) => `${formatTimeOfDay(window.start, clock)}-${formatTimeOfDay(window.end, clock)}`).join(', ') });
}

function shiftDayPath(staffId: number, date: string): string {
    return `/admin/shifts/${staffId}/days/${date}`;
}

export function DayEditor({ member, date, windows, source, patternWindows, clock, granularity, words, onClose }: Props) {
    const shell = useShell();
    const [edited, setEdited] = useState<ShiftWindow[]>(() => windows.map((window) => ({ ...window })));
    const { pending, write: send } = useShiftWrite<Action>(words.text('editor.not_saved'), onClose);

    const write = (action: Action) => {
        const path = shiftDayPath(member.id, date);

        send(action, (options) => (action === 'back' ? router.delete(path, options) : router.put(path, { windows: action === 'off' ? [] : edited }, options)));
    };

    const change = (at: number, end: 'start' | 'end', value: string) => setEdited((current) => current.map((window, index) => (index === at ? { ...window, [end]: value } : window)));

    const add = () => setEdited((current) => [...current, current[0] === undefined ? { ...(patternWindows[0] ?? DEFAULT_WINDOW) } : windowAfter(current[current.length - 1] ?? current[0])]);

    const remove = (at: number) => setEdited((current) => current.filter((_, index) => index !== at));

    const busy = pending !== null;

    const footer = (
        <div className="admin-shift-day__foot flex w-full flex-wrap items-center justify-between gap-2">
            <Button variant="link" destructive className="admin-shift-day__off h-8" disabled={busy && pending !== 'off'} busy={pending === 'off'} busyLabel={words.text('editor.saving')} onClick={() => write('off')}>
                {words.text('editor.not_working')}
            </Button>
            <div className="flex flex-wrap gap-2">
                {source === 'override' && (
                    <Button className="admin-shift-day__back" disabled={busy && pending !== 'back'} busy={pending === 'back'} busyLabel={words.text('editor.going_back')} onClick={() => write('back')}>
                        {words.text('editor.back')}
                    </Button>
                )}
                <Button variant="tertiary" className="admin-shift-day__cancel" onClick={onClose} disabled={busy}>
                    {words.text('editor.cancel')}
                </Button>
                <Button variant="primary" className="admin-shift-day__save" disabled={busy && pending !== 'save'} busy={pending === 'save'} busyLabel={words.text('editor.saving')} onClick={() => write('save')}>
                    {words.text('editor.save')}
                </Button>
            </div>
        </div>
    );

    return (
        <Modal open title={words.text('editor.title', { name: member.name, weekday: weekdayName(isoWeekday(date), 'long', shell.language), date: formatDate(date, clock) })} onClose={busy ? nothing : onClose} footer={footer} className="admin-shift-day max-w-lg">
            <div className="flex flex-col gap-3 text-sm">
                <p className="admin-shift-day__help text-xs text-muted-foreground">{words.text('editor.help')}</p>
                {edited.map((window, at) => (
                    <div key={at} className="admin-shift-day__window grid grid-cols-[1fr_1fr_auto] items-end gap-3" data-window={at}>
                        <Field id={`shift-day-start-${at}`} label={words.text('editor.start')}>
                            {(control) => <TimeField {...control} className="admin-shift-day__start" value={window.start} onChange={(value) => change(at, 'start', value)} clock={clock} granularity={granularity} disabled={busy} />}
                        </Field>
                        <Field id={`shift-day-end-${at}`} label={words.text('editor.end')}>
                            {(control) => <TimeField {...control} className="admin-shift-day__end" value={window.end} onChange={(value) => change(at, 'end', value)} clock={clock} granularity={granularity} disabled={busy} />}
                        </Field>
                        <Button variant="link" destructive className="admin-shift-day__remove h-8" disabled={busy} onClick={() => remove(at)}>
                            {words.text('editor.remove')}
                        </Button>
                    </div>
                ))}
                {edited.length < MOST_WINDOWS && (
                    <div>
                        <Button className="admin-shift-day__add" disabled={busy} onClick={add}>
                            {words.text(edited.length === 0 ? 'editor.add_first' : 'editor.add_second')}
                        </Button>
                    </div>
                )}
                <p className="admin-shift-day__summary">
                    <span className="admin-shift-day__hours">{hoursLine(windowMinutes(edited), words, 'editor')}</span> <span className="admin-shift-day__pattern">{patternLine(patternWindows, words, clock)}</span>
                </p>
            </div>
        </Modal>
    );
}
