import { formatInstant, formatInstantDay, formatInstantTime, type TenantClock } from '@/lib/format';
import { cn } from '@/lib/utils';

/*
 * An instant written as its day and its time, each part in a span of its own, so a list can set
 * the two apart: the bookings list reads the date in the muted ink and the time in bold, the
 * customers list the date in bold and the time plain. The text is always the shared formatter's
 * whole, the day, a space, then the time, in whatever clock the tenant chose. Where the formatter
 * cannot split the instant, a wire it cannot read or a format it does not understand, the whole
 * text stands alone with no parts.
 */

type Props = {
    wire: string;
    clock: TenantClock;
    /** The day's look. */
    day?: string;
    /** The time's look. */
    time?: string;
};

export function Instant({ wire, clock, day, time }: Props) {
    const whole = formatInstant(wire, clock);
    const dayText = formatInstantDay(wire, clock);
    const timeText = formatInstantTime(wire, clock);

    if (`${dayText} ${timeText}` !== whole) {
        return whole;
    }

    return (
        <>
            <span className={cn('admin-instant__day', day)}>{dayText}</span> <span className={cn('admin-instant__time', time)}>{timeText}</span>
        </>
    );
}
