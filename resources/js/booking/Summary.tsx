import type { ReactNode } from 'react';
import type { BookingItem } from './booking';
import { formatMoney } from './money';
import { formatMoment, wallClockOf } from './time';
import { useWords, type LineKey } from './words';

/*
 * What a booking is, item by item, shared by the review step and the manage view because it is
 * the same booking read the same way: the server's answer and never the app's own selections,
 * since the engine re-validated and re-priced the chain under lock and echoing local state could
 * show a price it never agreed to.
 *
 * Times render in the tenant's zone and money in the currency the booking carries, scaled by
 * that currency's own minor unit (7.1 and 7.2), both in the page's language, each item's day and
 * hour joined as ICU joins them, so no connector is spelled. An item carries no currency of its
 * own on the wire: the booking's is the currency every one of its rows was priced in, since the
 * total is their sum.
 *
 * Beside it, what every view showing a booking's card carries, each with its one key: the card's
 * heading, the total line, its word over the amount in the booking's own currency, and the one
 * line naming the tenant's time zone, which the when step carries too.
 */

/** The heading of the booking's own card, on the review step and the manage page. */
export const BOOKING_HEADING: LineKey = 'booking.heading';

/** The total line's word, on the review step, the manage page and every card of My bookings. */
export const TOTAL_LABEL: LineKey = 'booking.total';

/** The one line naming the tenant's time zone, its :zone the zone's name. */
export const ZONE_SENTENCE: LineKey = 'zone';

/** Who performs a sold item, where the answer names a person. */
export const WITH_STAFF: LineKey = 'summary.staff';

interface SummaryProps {
    readonly items: readonly BookingItem[];
    readonly currency: string;
    readonly timezone: string;
}

export function Summary({ items, currency, timezone }: SummaryProps): ReactNode {
    return (
        <ul className="kr-booking__list kr-booking__summary">
            {items.map((item) => (
                <li key={item.sort} className="kr-booking__item kr-booking__summary-item">
                    <SummaryItem item={item} currency={currency} timezone={timezone} />
                </li>
            ))}
        </ul>
    );
}

interface SummaryItemProps {
    readonly item: BookingItem;
    readonly currency: string;
    readonly timezone: string;
}

/**
 * One sold item: what it is, when it is in the tenant's clock, who performs it where the
 * answer names a person, and what it cost. No staff line where the answer named nobody: that
 * item runs on the tenant's implicit member, who is the business itself.
 */
function SummaryItem({ item, currency, timezone }: SummaryItemProps): ReactNode {
    const { text, locale } = useWords();

    return (
        <>
            <span className="kr-booking__summary-service">{item.serviceName}</span>
            <span className="kr-booking__summary-when">{formatMoment(wallClockOf(item.start, timezone), locale)}</span>
            {item.staffName !== null && <span className="kr-booking__summary-staff">{text(WITH_STAFF, { staff: item.staffName })}</span>}
            <span className="kr-booking__summary-price">{formatMoney(item.priceMinor, currency, locale)}</span>
        </>
    );
}

/**
 * A booking's total line: the word, then the amount in the booking's own currency, in the page's
 * language.
 */
export function TotalLine({ minor, currency }: { readonly minor: number; readonly currency: string }): ReactNode {
    const { text, locale } = useWords();

    return (
        <p className="kr-booking__total">
            <span>{text(TOTAL_LABEL)}</span> <span className="kr-booking__total-sum">{formatMoney(minor, currency, locale)}</span>
        </p>
    );
}

/** The one line naming the tenant's time zone (7.1). */
export function ZoneLine({ timezone }: { readonly timezone: string }): ReactNode {
    const { text } = useWords();

    return <p className="kr-booking__timezone">{text(ZONE_SENTENCE, { zone: timezone })}</p>;
}
