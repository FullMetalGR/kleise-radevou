import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useFocusFirstFailing } from '@/components/ui/field';
import { Notice } from '@/components/ui/notice';
import { clockOf, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import { type KeysOf, type Strings, useShell } from '@/lib/strings';
import { type Failure, NOT_DONE, owningFailure } from '@/lib/visits';
import { WeeklyEditor } from '@/settings/WeeklyEditor';
import type { WeeklyWindow } from '@/shifts/model';
import { useToasts } from '@/toast/toasts';
import type { SharedProps } from '@/types';
import type { Setup } from '../model';
import { type PrimaryChoice, useStepDirty, useStepMoves, useStepPage, useStepPrimary } from '../step';

/*
 * The Opening hours step: Settings' weekly editor over the week the server hands the step, the
 * business's own, or with the staff feature one week for every member the Shifts screen lists,
 * named above it with a warning where their weeks differ now. Save and continue writes the whole
 * week and moves on once it has landed; on a step already done whose week is unchanged the
 * primary is the wizard's Next, which writes nothing, so a member's own hours set elsewhere
 * survive a walk through the step. Where nobody the week is for holds hours yet there is nothing
 * set elsewhere to keep and the week shown is still nobody's, so the step saves it then too, done
 * or not. A week with no window cannot be saved, and says so.
 *
 * Without the staff feature but with several active members the hours are the members' own,
 * shown read-only in Settings, so the step says there is nothing to set and offers no write.
 *
 * A refusal naming one window is shown under it; any other is said in the house's error toast.
 */

/** Save and continue, PUT /admin/setup/hours, the whole week as rules. */
export const HOURS_URL = '/admin/setup/hours';

type HoursData = { mode: 'week' | 'members' | 'roster'; week: WeeklyWindow[]; members: string[]; differs: boolean; hourless: boolean };

type HoursStrings = { name: string; help: string; members: string; differs: string; roster: string; empty: string; save: string; saving: string };

type HoursWords = Strings<KeysOf<HoursStrings>>;

type PageProps = SharedProps & { setup?: Setup };

/** A week spelled window by window, by weekday, then start, then end, whatever order it lists them in. */
function spelled(week: readonly WeeklyWindow[]): string {
    return week
        .map((rule) => `${rule.weekday} ${rule.start} ${rule.end}`)
        .sort()
        .join(', ');
}

/** Whether two weeks hold the same windows: the editor adds a window after every other, so the order says nothing. */
function sameWeek(left: readonly WeeklyWindow[], right: readonly WeeklyWindow[]): boolean {
    return spelled(left) === spelled(right);
}

/** A refusal's sentence under the window it names by its place in the rules, or null where it names none. */
function refusalsOf(failure: Failure): Record<number, string> | null {
    const match = /^rules\.(\d+)(?:\.|$)/.exec(failure.field ?? '');

    return match === null || failure.sentence === null ? null : { [Number(match[1])]: failure.sentence };
}

/** Whether the step's fact holds on this answer. */
function doneOn(setup: Setup | undefined): boolean {
    return setup?.steps.find((fact) => fact.key === 'hours')?.done === true;
}

/** Save and continue where the step has a week to write; the wizard's Next otherwise. */
function primaryOf(write: boolean, rules: readonly WeeklyWindow[], saving: boolean, save: () => void, words: HoursWords): PrimaryChoice {
    if (!write) {
        return null;
    }

    return { label: words.text('save'), onPress: save, blocked: rules.length === 0 ? words.text('empty') : null, busy: saving ? words.text('saving') : null };
}

type FieldsProps = {
    data: HoursData;
    rules: readonly WeeklyWindow[];
    onChange: (rules: WeeklyWindow[]) => void;
    refusals: Readonly<Record<number, string>>;
    clock: TenantClock;
    granularity: number;
    words: HoursWords;
};

function HoursFields({ data, rules, onChange, refusals, clock, granularity, words }: FieldsProps) {
    const shell = useShell();
    const members = data.mode === 'members';
    // A refused save hands the focus to the first Start it marked, so the reader lands on the
    // window at fault and hears its sentence.
    const fields = useFocusFirstFailing<HTMLDivElement>(refusals);

    return (
        <div ref={fields} className="admin-setup-hours flex flex-col gap-3">
            <p className="admin-setup-hours__help text-sm text-muted-foreground">{words.text('help')}</p>
            {members && <p className="admin-setup-hours__members text-sm">{words.text('members', { names: listOf(data.members, shell.language) })}</p>}
            {members && data.differs && (
                <Notice kind="warning" className="admin-setup-hours__differs">
                    {words.text('differs')}
                </Notice>
            )}
            <WeeklyEditor rules={rules} onChange={onChange} clock={clock} granularity={granularity} idPrefix="setup-hours" refusals={refusals} />
        </div>
    );
}

function Roster({ words }: { words: HoursWords }) {
    return (
        <div className="admin-setup-hours flex flex-col gap-3">
            <Notice kind="info" className="admin-setup-hours__roster">
                {words.text('roster')}
            </Notice>
        </div>
    );
}

type HoursWrite = { save: (rules: WeeklyWindow[]) => void; saving: boolean; refusals: Readonly<Record<number, string>>; forget: () => void };

/**
 * The step's one write: the whole week put by a visit that keeps the page, busy until it lands,
 * the wizard moving on once it has; a refusal naming a window kept under it until the week is
 * edited or saved again, any other said in the error toast.
 */
function useHoursWrite(): HoursWrite {
    const { saved } = useStepMoves();
    const shell = useShell();
    const toasts = useToasts();
    const [saving, setSaving] = useState(false);
    const [refusals, setRefusals] = useState<Record<number, string>>({});

    const refuse = (failure: Failure) => {
        const named = refusalsOf(failure);

        if (named === null) {
            toasts.push('error', failure.sentence ?? shell.text(NOT_DONE));
        } else {
            setRefusals(named);
        }
    };

    const save = (rules: WeeklyWindow[]) => {
        const owned = owningFailure(refuse, shell);

        router.put(
            HOURS_URL,
            { rules },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    setSaving(true);
                    setRefusals({});
                    owned.onStart();
                },
                onSuccess: saved,
                onFinish: () => {
                    owned.onFinish();
                    setSaving(false);
                },
            },
        );
    };

    return { save, saving, refusals, forget: () => setRefusals({}) };
}

export function HoursStep() {
    const { data, words } = useStepPage<HoursData, HoursStrings>();
    const { props } = usePage<PageProps>();
    const { save, saving, refusals, forget } = useHoursWrite();
    const [rules, setRules] = useState<WeeklyWindow[]>(() => [...(data?.week ?? [])]);
    const editing = data !== null && data.mode !== 'roster';
    const changed = editing && !sameWeek(rules, data.week);

    const change = (next: WeeklyWindow[]) => {
        setRules(next);
        forget();
    };

    // A week to write: the step is open, or the week was changed, or nobody holds the one shown yet.
    const write = editing && (changed || !doneOn(props.setup) || data.hourless);

    useStepDirty(changed);
    useStepPrimary(primaryOf(write, rules, saving, () => save(rules), words));

    if (data === null) {
        return null;
    }

    return data.mode === 'roster' ? <Roster words={words} /> : <HoursFields data={data} rules={rules} onChange={change} refusals={refusals} clock={clockOf(props)} granularity={props.settings.granularity} words={words} />;
}
