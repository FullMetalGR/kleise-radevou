import { Button } from '@/components/ui/button';
import { ErrorLine, Field } from '@/components/ui/field';
import { TimeField } from '@/components/ui/time-field';
import type { TenantClock } from '@/lib/format';
import { ISO_WEEKDAYS, weekdayName } from '@/lib/names';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { DEFAULT_WINDOW, type WeeklyWindow } from '@/shifts/model';

/*
 * The weekly availability editor: a list of seven day rows, each holding zero or more Start and
 * End window lines with a Remove, and an Add window; a day with no windows is a closed day. It
 * takes the windows and a change handler and knows nothing about the page around it. Settings' Opening hours
 * renders it over the implicit member's week for a tenant without the staff feature; a member's
 * hours are edited on Shifts, whose pattern editor draws its own weekday rows (PatternDay, with a
 * tick per day and at most two windows), so this is Opening hours' editor alone.
 *
 * The rows are time fields, so a window stored off the granularity grid is shown and kept, never
 * snapped: only a person picking another time moves it. The rules keep their order; a day's rows
 * are its rules in that order.
 *
 * Its words are the shell's shared ones, since two screens read them, and each day is named by ICU
 * in the page's language. A refusal naming one window, by its place in the rules, is a sentence on
 * a line of its own under that window's fields and Remove, as wide as the window, and the window's
 * Start points at it and is marked as failing; a blank sentence is no refusal.
 *
 * The editor is the size container its rows ask, so a row reads the same in Settings and in the
 * setup wizard's dialog. Where the editor is at least 48rem wide a row is one line of three
 * columns: the day's name, its windows under each other, and Add window on the first window's
 * line, each held to the foot of that line so the name and the closed day's word sit level with
 * the fields. Below that the row stacks in the order it is written. A day of two windows in Greek
 * needs a little under 48rem for its line, the longest the row has to hold.
 *
 * A time field is 8rem wide on the one line. In a stacked row the two share what Remove leaves of
 * the window's width, 8rem each at most and never narrower than the time they show, so Remove
 * stays beside them on a phone and moves under them only where a time would otherwise be cut.
 */

/** A day's row: one column, and from a 48rem editor the name's, the windows' and Add window's. */
const ROW = 'admin-weekly__day grid gap-y-2 py-4 first:pt-0 last:pb-0 @3xl:grid-cols-[8rem_minmax(0,1fr)_auto] @3xl:items-end @3xl:gap-x-4';

/** A window: its two fields and Remove on a line that wraps, held to the line's foot. */
const WINDOW = 'admin-weekly__window flex min-w-0 flex-wrap items-end gap-2 @3xl:col-start-2';

/**
 * A refused window on the one line takes two of the row's own lines, the fields and Remove on the
 * first and the sentence across the second, so the row's first line stays as tall as the fields
 * and the day's name and Add window stay level with them whichever window is refused.
 */
const REFUSED = '@3xl:row-span-2 @3xl:grid @3xl:grid-cols-[auto_auto_auto_minmax(0,1fr)] @3xl:grid-rows-subgrid';

/** A time field's width: a share of the stacked window's line between its time and 8rem, and 8rem on the one line. */
const TIME = 'min-w-max max-w-32 flex-1 @3xl:w-32 @3xl:flex-none';

type Props = {
    rules: readonly WeeklyWindow[];
    onChange: (rules: WeeklyWindow[]) => void;
    clock: TenantClock;
    granularity: number;
    idPrefix?: string;
    /** A refusal's sentence by the place in the rules of the window it names; none where absent. */
    refusals?: Readonly<Record<number, string>>;
};

export function WeeklyEditor({ rules, onChange, clock, granularity, idPrefix = 'weekly', refusals = {} }: Props) {
    const replace = (index: number, rule: WeeklyWindow) => onChange(rules.map((current, at) => (at === index ? rule : current)));
    const remove = (index: number) => onChange(rules.filter((_, at) => at !== index));
    const add = (weekday: number) => onChange([...rules, { weekday, ...DEFAULT_WINDOW }]);
    const shell = useShell();

    return (
        <div className="admin-weekly @container flex flex-col divide-y divide-border">
            {ISO_WEEKDAYS.map((weekday) => {
                const rows = rules.map((rule, index) => ({ rule, index })).filter(({ rule }) => rule.weekday === weekday);

                return (
                    <section key={weekday} className={ROW} data-weekday={weekday} aria-labelledby={`${idPrefix}-day-${weekday}`}>
                        <h3 id={`${idPrefix}-day-${weekday}`} className="text-body font-semibold @3xl:col-start-1 @3xl:row-start-1 @3xl:pb-2.5">
                            {weekdayName(weekday, 'long', shell.language)}
                        </h3>
                        {rows.length === 0 && <p className="admin-weekly__closed text-body-sm text-muted-foreground @3xl:col-start-2 @3xl:row-start-1 @3xl:pb-3">{shell.text('shared.weekly.closed')}</p>}
                        {rows.map(({ rule, index }, position) => {
                            const startId = `${idPrefix}-${weekday}-${position}-start`;
                            const refusal = refusals[index] ?? '';
                            const refused = refusal.trim() !== '';

                            return (
                                <div key={index} className={cn(WINDOW, refused && REFUSED)}>
                                    <Field id={startId} label={shell.text('shared.weekly.start')} className={TIME}>
                                        {(control) => (
                                            <TimeField
                                                {...control}
                                                aria-describedby={refused ? `${startId}-error` : undefined}
                                                aria-invalid={refused || undefined}
                                                className="admin-weekly__start"
                                                value={rule.start}
                                                onChange={(start) => replace(index, { ...rule, start })}
                                                clock={clock}
                                                granularity={granularity}
                                            />
                                        )}
                                    </Field>
                                    <Field id={`${idPrefix}-${weekday}-${position}-end`} label={shell.text('shared.weekly.end')} className={TIME}>
                                        {(control) => <TimeField {...control} className="admin-weekly__end" value={rule.end} onChange={(end) => replace(index, { ...rule, end })} clock={clock} granularity={granularity} />}
                                    </Field>
                                    <Button variant="tertiary" className="admin-weekly__remove" onClick={() => remove(index)}>
                                        {shell.text('shared.weekly.remove')}
                                    </Button>
                                    {refused && (
                                        <div className="basis-full @3xl:col-span-full">
                                            <ErrorLine id={`${startId}-error`}>{refusal}</ErrorLine>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                        <Button variant="secondary" className="admin-weekly__add justify-self-start @3xl:col-start-3 @3xl:row-start-1" onClick={() => add(weekday)}>
                            {shell.text('shared.weekly.add')}
                        </Button>
                    </section>
                );
            })}
        </div>
    );
}
