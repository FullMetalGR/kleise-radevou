import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { Plus } from '@/components/glyphs';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field } from '@/components/ui/field';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { TimeField } from '@/components/ui/time-field';
import { formatCount, type TenantClock } from '@/lib/format';
import { weekdayName } from '@/lib/names';
import { placed, useShell } from '@/lib/strings';
import { DEFAULT_WINDOW, type ShiftMember, type ShiftPattern, type WeeklyWindow, windowAfter, windowMinutes } from './model';
import { useShiftWrite } from './useShiftWrite';
import { hoursLine, hoursOf, MOST_WINDOWS, type ShiftWords } from './words';

/*
 * The Shifts pattern editor: one member's repeating week, titled "<name>, repeating pattern",
 * opened from the row head's Pattern button. The note on what a save reaches; Repeats (every week,
 * every 2, 3 or 4 weeks), Starts on, shown above a cadence of one, and Ends, never or on a date, in
 * one row; the line on the cadence; per weekday, in the tenant's week order, a row of a checkbox,
 * the weekday with its hours beneath, and zero, one or two windows, each one whole line around its
 * Start and End time fields (pattern.window), a plus adding the second and Remove as a destructive
 * text action, or Not working; the line of the week's hours; Cancel and Save pattern, PUT
 * /admin/shifts/{id}/pattern, the week replace-all.
 *
 * A weekly pattern starts on the week holding 2000-01-01, which the server writes whatever the body
 * names, so the field is not shown for one: a date there would say something the save does not
 * keep. A cadence above one counts its weeks from the week holding Starts on, written as entered;
 * the field opens on the stored start of such a pattern, else on the first day of the week the
 * screen shows, which is then week one.
 *
 * A day is ticked while it holds a window: ticking it gives it the first window of the nearest
 * working day before it, else 09:00-17:00; unticking it or removing its last window closes it.
 *
 * Nothing is optimistic: Save shows busy and everything else waits, Cancel, Escape and a backdrop
 * press among them; the server's flash says it landed, the redirect back answers the page's props
 * again so the row head and the grid redraw from the server, and the editor closes. A refusal is
 * the envelope's sentence as an error toast, the editor kept open with what was entered.
 */

type Props = {
    member: ShiftMember;
    pattern: ShiftPattern | undefined;
    /** The ISO weekdays in the tenant's week order, as the grid's columns run. */
    weekdays: readonly number[];
    /** The first day of the week the screen shows: Starts on's first value for a new cadence. */
    weekFrom: string;
    clock: TenantClock;
    weekStart: number;
    granularity: number;
    words: ShiftWords;
    onClose: () => void;
};

type Ends = 'never' | 'on';

/** The cadences Repeats offers, ShiftPattern::MAX_CADENCE the last. */
const CADENCES = [1, 2, 3, 4] as const;

/** What Escape and a backdrop press do while the save is in flight: nothing, as Cancel is disabled. */
function nothing(): void {}

/** The window a newly ticked day opens with: the nearest working day's first before it, else the default. */
function openingOf(windows: readonly WeeklyWindow[], weekdays: readonly number[], weekday: number): { start: string; end: string } {
    const before = weekdays.slice(0, weekdays.indexOf(weekday)).reverse();
    const near = before.map((day) => windows.find((window) => window.weekday === day)).find((window) => window !== undefined);

    return near === undefined ? { ...DEFAULT_WINDOW } : { start: near.start, end: near.end };
}

type DayProps = {
    weekday: number;
    windows: readonly WeeklyWindow[];
    busy: boolean;
    clock: TenantClock;
    granularity: number;
    words: ShiftWords;
    onTick: (on: boolean) => void;
    onChange: (at: WeeklyWindow, end: 'start' | 'end', value: string) => void;
    onAdd: (first: WeeklyWindow) => void;
    onRemove: (at: WeeklyWindow) => void;
};

/**
 * A weekday's row of the grid: the checkbox, the weekday, the window's line across three
 * columns, the plus and Remove.
 */
const ROW = 'grid grid-cols-[1.5rem_7.5rem_minmax(0,1fr)_auto_minmax(0,1fr)_2rem_auto] items-center gap-2';

/** The window line's words between and around its two fields. */
function windowWords(words: string): ReactNode {
    return <span className="admin-shift-pattern__to shrink-0 text-muted-foreground">{words}</span>;
}

/**
 * One weekday's rows: its checkbox named by ICU's weekday and the weekday with the day's hours
 * beneath, then a row a window, Start to End with Remove and on the first the plus adding a second
 * window; or Not working across the row where the day has none.
 */
function PatternDay({ weekday, windows, busy, clock, granularity, words, onTick, onChange, onAdd, onRemove }: DayProps) {
    const shell = useShell();
    const first = windows[0];
    const tickId = `shift-pattern-${weekday}-tick`;
    const head = (
        <>
            <input type="checkbox" id={tickId} className="admin-shift-pattern__tick size-4 accent-primary" checked={first !== undefined} disabled={busy} onChange={(event) => onTick(event.target.checked)} />
            <span className="flex flex-col">
                <label htmlFor={tickId} className="font-medium">
                    {weekdayName(weekday, 'long', shell.language)}
                </label>
                {first !== undefined && <span className="admin-shift-pattern__day-total text-xs text-muted-foreground">{hoursOf(windowMinutes(windows), words)}</span>}
            </span>
        </>
    );

    return (
        <div className="admin-shift-pattern__day flex flex-col gap-2 py-1.5" data-weekday={weekday}>
            {first === undefined ? (
                <div className={ROW}>
                    {head}
                    <span className="admin-shift-pattern__off col-span-5 text-muted-foreground">{words.text('pattern.not_working')}</span>
                </div>
            ) : (
                windows.map((window, position) => (
                    <div key={position} className={`admin-shift-pattern__window ${ROW}`}>
                        {position === 0 ? head : <span className="col-span-2" />}
                        <span className="admin-shift-pattern__span col-span-3 flex min-w-0 items-center gap-2">
                            {placed(
                                words.text('pattern.window'),
                                {
                                    start: (
                                        <TimeField
                                            id={`shift-pattern-${weekday}-${position}-start`}
                                            aria-label={words.text('pattern.start')}
                                            className="admin-shift-pattern__start min-w-0 flex-1"
                                            value={window.start}
                                            onChange={(value) => onChange(window, 'start', value)}
                                            clock={clock}
                                            granularity={granularity}
                                            disabled={busy}
                                        />
                                    ),
                                    end: (
                                        <TimeField
                                            id={`shift-pattern-${weekday}-${position}-end`}
                                            aria-label={words.text('pattern.end')}
                                            className="admin-shift-pattern__end min-w-0 flex-1"
                                            value={window.end}
                                            onChange={(value) => onChange(window, 'end', value)}
                                            clock={clock}
                                            granularity={granularity}
                                            disabled={busy}
                                        />
                                    ),
                                },
                                windowWords,
                            )}
                        </span>
                        {position === 0 && windows.length < MOST_WINDOWS ? (
                            <Button variant="tertiary" className="admin-shift-pattern__add w-8 px-0" aria-label={words.text('pattern.add_second')} disabled={busy} onClick={() => onAdd(first)}>
                                <Plus />
                            </Button>
                        ) : (
                            <span />
                        )}
                        <Button variant="link" destructive className="admin-shift-pattern__remove h-8" disabled={busy} onClick={() => onRemove(window)}>
                            {words.text('pattern.remove')}
                        </Button>
                    </div>
                ))
            )}
        </div>
    );
}

function patternPath(staffId: number): string {
    return `/admin/shifts/${staffId}/pattern`;
}

export function PatternEditor({ member, pattern, weekdays, weekFrom, clock, weekStart, granularity, words, onClose }: Props) {
    const [cadence, setCadence] = useState<number>(pattern?.cadence_weeks ?? 1);
    const [startsOn, setStartsOn] = useState<string>(pattern !== undefined && pattern.cadence_weeks > 1 ? pattern.starts_on : weekFrom);
    const [ends, setEnds] = useState<Ends>(pattern?.ends_on == null ? 'never' : 'on');
    const [endsOn, setEndsOn] = useState<string>(pattern?.ends_on ?? '');
    const [windows, setWindows] = useState<WeeklyWindow[]>(() => (pattern?.windows ?? []).map((window) => ({ ...window })));
    const { pending, write } = useShiftWrite<'save'>(words.text('pattern.not_saved'), onClose);
    const busy = pending !== null;

    const save = () => {
        const ordered = weekdays.flatMap((weekday) => windows.filter((window) => window.weekday === weekday));

        write('save', (options) => router.put(patternPath(member.id), { cadence_weeks: cadence, starts_on: startsOn, ends_on: ends === 'on' ? endsOn : null, windows: ordered }, options));
    };

    const tick = (weekday: number, on: boolean) =>
        setWindows((current) => (on ? [...current, { weekday, ...openingOf(current, weekdays, weekday) }] : current.filter((window) => window.weekday !== weekday)));

    const change = (at: WeeklyWindow, end: 'start' | 'end', value: string) => setWindows((current) => current.map((window) => (window === at ? { ...window, [end]: value } : window)));

    const add = (first: WeeklyWindow) => setWindows((current) => [...current, { weekday: first.weekday, ...windowAfter(first) }]);

    const remove = (at: WeeklyWindow) => setWindows((current) => current.filter((window) => window !== at));

    const footer = (
        <div className="flex w-full justify-end gap-2">
            <Button className="admin-shift-pattern__cancel" onClick={onClose} disabled={busy}>
                {words.text('pattern.cancel')}
            </Button>
            <Button variant="primary" className="admin-shift-pattern__save" disabled={ends === 'on' && endsOn === ''} busy={busy} busyLabel={words.text('pattern.saving')} onClick={save}>
                {words.text('pattern.save')}
            </Button>
        </div>
    );

    return (
        <Modal open title={words.text('pattern.title', { name: member.name })} onClose={busy ? nothing : onClose} footer={footer} className="admin-shift-pattern max-w-2xl">
            <div className="flex flex-col gap-3 text-sm">
                <p className="admin-shift-pattern__note text-xs text-muted-foreground">{words.text('pattern.note')}</p>
                <div className="grid gap-3 desktop:grid-cols-3">
                    <Field id="shift-pattern-repeats" label={words.text('pattern.repeats')}>
                        {(control) => (
                            <Select
                                {...control}
                                className="admin-shift-pattern__repeats w-full"
                                disabled={busy}
                                value={String(cadence)}
                                onChange={(value) => setCadence(Number(value))}
                                options={CADENCES.map((weeks) => ({ value: String(weeks), label: weeks === 1 ? words.text('pattern.every_week') : words.text('pattern.every_weeks', { weeks: formatCount(weeks, words.locale) }) }))}
                            />
                        )}
                    </Field>
                    {cadence > 1 && (
                        <Field id="shift-pattern-starts" label={words.text('pattern.starts_on')}>
                            {(control) => <DateField {...control} className="admin-shift-pattern__starts" value={startsOn} onChange={setStartsOn} clock={clock} weekStart={weekStart} disabled={busy} />}
                        </Field>
                    )}
                    <Field id="shift-pattern-ends" label={words.text('pattern.ends')}>
                        {(control) => (
                            <Select
                                {...control}
                                className="admin-shift-pattern__ends w-full"
                                disabled={busy}
                                value={ends}
                                onChange={(value) => setEnds(value === 'on' ? 'on' : 'never')}
                                options={[
                                    { value: 'never', label: words.text('pattern.never') },
                                    { value: 'on', label: words.text('pattern.on_date') },
                                ]}
                            />
                        )}
                    </Field>
                    {ends === 'on' && (
                        <Field id="shift-pattern-ends-on" label={words.text('pattern.ends_on')}>
                            {(control) => <DateField {...control} className="admin-shift-pattern__ends-on" value={endsOn} onChange={setEndsOn} clock={clock} weekStart={weekStart} disabled={busy} />}
                        </Field>
                    )}
                </div>
                <p className="admin-shift-pattern__help text-xs text-muted-foreground">{cadence === 1 ? words.text('pattern.help_weekly') : words.text('pattern.help_cadence', { weeks: formatCount(cadence, words.locale) })}</p>
                <div className="flex flex-col divide-y">
                    {weekdays.map((weekday) => (
                        <PatternDay
                            key={weekday}
                            weekday={weekday}
                            windows={windows.filter((window) => window.weekday === weekday)}
                            busy={busy}
                            clock={clock}
                            granularity={granularity}
                            words={words}
                            onTick={(on) => tick(weekday, on)}
                            onChange={change}
                            onAdd={add}
                            onRemove={remove}
                        />
                    ))}
                </div>
                <p className="admin-shift-pattern__summary">{hoursLine(windowMinutes(windows), words, 'pattern')}</p>
            </div>
        </Modal>
    );
}
