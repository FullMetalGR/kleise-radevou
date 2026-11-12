import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { TimeField } from '@/components/ui/time-field';
import type { TenantClock } from '@/lib/format';
import { ISO_WEEKDAYS, weekdayName } from '@/lib/names';
import { useShell } from '@/lib/strings';
import { DEFAULT_WINDOW, type WeeklyWindow } from '@/shifts/model';

/*
 * The weekly availability editor: seven day cards, each holding zero or more Start and End window
 * rows with a Remove, and an Add window; a day with no windows is a closed day. It takes the
 * windows and a change handler and knows nothing about the page around it. Settings' Opening hours
 * renders it over the implicit member's week for a tenant without the staff feature; a member's
 * hours are edited on Shifts, whose pattern editor draws its own weekday rows (PatternDay, with a
 * tick per day and at most two windows), so this is Opening hours' editor alone.
 *
 * The rows are time fields, so a window stored off the granularity grid is shown and kept, never
 * snapped: only a person picking another time moves it. The rules keep their order; a day's rows
 * are its rules in that order.
 *
 * Its words are the shell's shared ones, since two screens read them, and each day is named by ICU
 * in the page's language.
 */

type Props = {
    rules: readonly WeeklyWindow[];
    onChange: (rules: WeeklyWindow[]) => void;
    clock: TenantClock;
    granularity: number;
    idPrefix?: string;
};

export function WeeklyEditor({ rules, onChange, clock, granularity, idPrefix = 'weekly' }: Props) {
    const replace = (index: number, rule: WeeklyWindow) => onChange(rules.map((current, at) => (at === index ? rule : current)));
    const remove = (index: number) => onChange(rules.filter((_, at) => at !== index));
    const add = (weekday: number) => onChange([...rules, { weekday, ...DEFAULT_WINDOW }]);
    const shell = useShell();

    return (
        <div className="admin-weekly grid gap-3 sm:col-span-2 desktop:grid-cols-2 lg:grid-cols-3">
            {ISO_WEEKDAYS.map((weekday) => {
                const rows = rules.map((rule, index) => ({ rule, index })).filter(({ rule }) => rule.weekday === weekday);

                return (
                    <section key={weekday} className="admin-weekly__day rounded-lg border p-3" data-weekday={weekday} aria-labelledby={`${idPrefix}-day-${weekday}`}>
                        <h3 id={`${idPrefix}-day-${weekday}`} className="mb-2 font-medium">
                            {weekdayName(weekday, 'long', shell.language)}
                        </h3>
                        {rows.length === 0 && <p className="admin-weekly__closed text-sm text-muted-foreground">{shell.text('shared.weekly.closed')}</p>}
                        {rows.map(({ rule, index }, position) => (
                            <div key={index} className="admin-weekly__window mb-2 flex flex-wrap items-end gap-2">
                                <Field id={`${idPrefix}-${weekday}-${position}-start`} label={shell.text('shared.weekly.start')}>
                                    {(control) => <TimeField {...control} className="admin-weekly__start" value={rule.start} onChange={(start) => replace(index, { ...rule, start })} clock={clock} granularity={granularity} />}
                                </Field>
                                <Field id={`${idPrefix}-${weekday}-${position}-end`} label={shell.text('shared.weekly.end')}>
                                    {(control) => <TimeField {...control} className="admin-weekly__end" value={rule.end} onChange={(end) => replace(index, { ...rule, end })} clock={clock} granularity={granularity} />}
                                </Field>
                                <Button variant="tertiary" className="admin-weekly__remove" onClick={() => remove(index)}>
                                    {shell.text('shared.weekly.remove')}
                                </Button>
                            </div>
                        ))}
                        <Button variant="secondary" className="admin-weekly__add" onClick={() => add(weekday)}>
                            {shell.text('shared.weekly.add')}
                        </Button>
                    </section>
                );
            })}
        </div>
    );
}
