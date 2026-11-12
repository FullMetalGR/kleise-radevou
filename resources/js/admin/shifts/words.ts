import { unitFigure } from '@/lib/names';
import type { KeysOf, Strings } from '@/lib/strings';
import type { ShiftsStrings } from '@/types';

/*
 * What the Shifts editors share: the words of the shifts section, which the mounting screen's
 * controller names; the most windows a day of either editor offers; a figure of hours; and the
 * line of the hours their windows cover, each editor's under its own section of shifts.php.
 */

type ShiftKey = KeysOf<ShiftsStrings>;

export type ShiftWords = Strings<ShiftKey>;

/**
 * The most windows a day offers in either editor; the server takes any ordered, non-overlapping
 * list.
 */
export const MOST_WINDOWS = 2;

/**
 * A figure of minutes as hours, with the minutes where there are any: "44h", "15h 30m" in
 * English, each figure and its unit ICU's in the language (lib/names.ts): the grid's totals, a
 * pattern day's and both editors' hours lines.
 */
export function hoursOf(minutes: number, words: ShiftWords): string {
    const rest = minutes % 60;
    const hours = unitFigure(Math.floor(minutes / 60), 'hour', words.language);

    return rest === 0 ? hours : words.text('page.hours_minutes', { hours, minutes: unitFigure(rest, 'minute', words.language) });
}

/** Each editor's line of the hours its windows cover: the day editor's and the pattern editor's. */
const HOURS_KEYS = { editor: 'editor.hours', pattern: 'pattern.hours' } as const satisfies Record<string, ShiftKey>;

/**
 * The hours the windows cover as an editor's line spells them, the figure the grid's totals
 * read (hoursOf): a whole day or week of hours an hour figure alone, never a spelled count.
 */
export function hoursLine(minutes: number, words: ShiftWords, editor: keyof typeof HOURS_KEYS): string {
    return words.text(HOURS_KEYS[editor], { hours: hoursOf(minutes, words) });
}
