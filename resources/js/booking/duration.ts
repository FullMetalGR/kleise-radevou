import type { LineKey, Words } from './words';

/*
 * A duration as the booking app spells one, on the service cards, in the summary card and its
 * phone bar, and on every later step: "45 min", "1 hr", "1 hr 30 min", "2 hr" in English. One
 * helper, so no two surfaces spell the same length two ways. Each shape is a whole line of
 * booking.php read as a choice, minutes alone, hours alone, and hours with minutes, so a
 * language whose words change with the number says so in its own line and no shape is assembled
 * from a number and a word.
 */

const MINUTES_PER_HOUR = 60;

/** Minutes alone, chosen by the minutes. */
export const DURATION_MINUTES: LineKey = 'duration.minutes';

/** Hours alone, chosen by the hours. */
export const DURATION_HOURS: LineKey = 'duration.hours';

/** Hours with minutes, chosen by the hours, the minutes as :minutes. */
export const DURATION_HOURS_MINUTES: LineKey = 'duration.hours_minutes';

export function formatDuration(minutes: number, words: Words): string {
    const hours = Math.floor(minutes / MINUTES_PER_HOUR);
    const rest = minutes % MINUTES_PER_HOUR;

    if (hours === 0) {
        return words.choice(DURATION_MINUTES, rest);
    }

    return rest === 0 ? words.choice(DURATION_HOURS, hours) : words.choice(DURATION_HOURS_MINUTES, hours, { minutes: String(rest) });
}
