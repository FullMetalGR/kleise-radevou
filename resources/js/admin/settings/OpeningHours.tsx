import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { formatTimeOfDay, type TenantClock } from '@/lib/format';
import { ISO_WEEKDAYS, weekdayName } from '@/lib/names';
import type { WeeklyWindow } from '@/shifts/model';
import { WeeklyEditor } from './WeeklyEditor';
import type { HoursBranch, RosterMember } from './model';
import { type SettingsKey, type SettingsWords, useSettings } from './words';

/*
 * The Opening hours section, present only while the staff feature is absent. It is the shared
 * weekly editor over the implicit member's usual week, with its own Save whose write is
 * replace-all over the whole week to a route naming no member, the server writing it as the
 * implicit member's weekly pattern; or, on a tenant that lost the feature while several members
 * stay active, a read-only roster under its own sentence, every active member with the usual
 * week of their pattern spelled in the tenant's time format with no conversion, the cadence and
 * the dates edited alone not drawn.
 */

/** The sentence over the read-only roster, a key of the hours group. */
export const ROSTER_SENTENCE = 'hours.roster' satisfies SettingsKey;

/**
 * A roster day's line, whole: the weekday's ICU name with its windows, each in the tenant's time
 * format with no conversion, or the closed day's line.
 */
function dayLine(weekday: number, windows: readonly WeeklyWindow[], clock: TenantClock, words: SettingsWords): string {
    const day = weekdayName(weekday, 'long', words.language);

    if (windows.length === 0) {
        return words.text('hours.closed_day', { day });
    }

    const spelled = windows.map((rule) => words.text('hours.window', { start: formatTimeOfDay(rule.start, clock), end: formatTimeOfDay(rule.end, clock) }));

    return words.text('hours.day', { day, windows: spelled.join(words.text('hours.separator')) });
}

type Props = {
    branch: Exclude<HoursBranch, 'absent'>;
    week: readonly WeeklyWindow[];
    roster: readonly RosterMember[] | null;
    clock: TenantClock;
    granularity: number;
    saving: boolean;
    wait: string | null;
    onSave: (rules: WeeklyWindow[]) => void;
};

export function OpeningHours({ branch, week, roster, clock, granularity, saving, wait, onSave }: Props) {
    const words = useSettings();
    const [rules, setRules] = useState<WeeklyWindow[]>([...week]);

    if (branch === 'roster') {
        return (
            <div className="admin-settings-roster flex flex-col gap-3 sm:col-span-2">
                <Notice kind="info" className="admin-settings-roster__notice">
                    {words.text(ROSTER_SENTENCE)}
                </Notice>
                <ul className="admin-settings-roster__list flex flex-col gap-3">
                    {(roster ?? []).map((member) => (
                        <li key={member.id} className="admin-settings-roster__member">
                            <p className="admin-settings-roster__name font-medium">{member.name}</p>
                            <ul className="admin-settings-roster__windows text-sm text-muted-foreground">
                                {ISO_WEEKDAYS.map((weekday) => {
                                    const windows = member.windows.filter((rule) => rule.weekday === weekday);

                                    return (
                                        <li key={weekday} className="admin-settings-roster__day" data-weekday={weekday}>
                                            {dayLine(weekday, windows, clock, words)}
                                        </li>
                                    );
                                })}
                            </ul>
                        </li>
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <div className="admin-settings-hours flex flex-col gap-3 sm:col-span-2">
            <p className="text-sm text-muted-foreground">{words.text('hours.intro')}</p>
            <WeeklyEditor rules={rules} onChange={setRules} clock={clock} granularity={granularity} idPrefix="opening" />
            <div className="flex items-center gap-3">
                <Button variant="primary" className="admin-settings-hours-save" disabled={wait !== null} busy={saving} busyLabel={words.text('hours.saving')} onClick={() => onSave(rules)}>
                    {words.text('hours.save')}
                </Button>
                {wait !== null && (
                    <p className="admin-settings-hours__wait text-sm text-muted-foreground" role="status">
                        {wait}
                    </p>
                )}
            </div>
        </div>
    );
}
