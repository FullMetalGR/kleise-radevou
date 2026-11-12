import { useId, type ReactNode } from 'react';
import { serviceById, type Catalog, type CatalogService } from './catalog';
import { formatDuration } from './duration';
import type { Segment, StripName } from './flow';
import { CrossIcon } from './icons';
import { initialsOf } from './initials';
import { formatMoney } from './money';
import { nameOf } from './ServiceStep';
import { formatDay, formatMoment, wallClockOf } from './time';
import { useWords, type LineKey, type Words } from './words';

/*
 * The summary card: from the service step on, with or without segments, beside the step where the
 * frame is wide enough, under it where it is not, and at or under 640 pixels the bar stuck to the
 * bottom of the screen. At its head the business, an initial on a rounded accent square and the
 * name. Then, with no segment, the one line saying to pick a service; else a line per segment: the
 * service's name with its duration under it, extended from the staff step on by who performs it
 * where the service lists anybody, the price from the catalogue's row in that row's own currency at
 * the right (7.2), and on the service step alone a remove control named for the service; then the
 * day once one is chosen on when, and the day and the time once a time is; then the total duration
 * and the total price, labelled as an estimate; then the one Continue the flow hands it.
 *
 * It is never the number the customer confirms: the review step's summary is read off the booking
 * the server answered, and this card is gone by then. A chain whose rows carry two currencies has
 * no total price, since no sum across currencies means anything; each row keeps its own. A segment
 * whose service the catalogue no longer lists is named by the placeholder and counted in neither
 * total, and before the catalogue has answered no line is shown at all rather than every one named
 * as gone.
 *
 * A region named by its visible label. It holds the remove controls and the Continue but no live
 * region: its total announces nothing, and a removal is announced by the service step's own region,
 * through the flow. The phone bar is this same card in its compact form: the stylesheet hides
 * everything but the sum, the brief count and the Continue, so there is one Continue and one set of
 * numbers at every width. While the card holds no segment it carries the empty modifier, and at a
 * phone's width the stylesheet hides it then, so the hosted page's own Book now bar keeps the
 * phone's bottom edge until the first service is chosen.
 */

export const ESTIMATE_LABEL: LineKey = 'estimate.label';

export const ESTIMATE_TOTAL: LineKey = 'estimate.total';

/** The card's Continue wherever it moves the flow on. */
export const CONTINUE_LABEL: LineKey = 'estimate.continue';

/** The card's Continue on when, disabled: a pick is the transition, and this says so within it. */
export const PICK_A_TIME_LABEL: LineKey = 'estimate.pick_a_time';

/** The card's one line before the first segment. */
export const PICK_A_SERVICE: LineKey = 'estimate.pick_a_service';

/**
 * A line's duration with who performs it, from the staff step on: one whole line for a service the
 * customer named nobody for, and one with the person's name as its :staff, each its :duration.
 */
export const ANY_PROFESSIONAL_LINE: LineKey = 'estimate.any';

export const WITH_STAFF_LINE: LineKey = 'estimate.staff';

/** The phone bar's brief, read as a choice by the number of services, its :duration their total. */
export const BRIEF_LINE: LineKey = 'estimate.brief';

/** A line's remove control's name, its :service the line's. */
export const REMOVE_LABEL: LineKey = 'estimate.remove';

/**
 * The card's one Continue as the flow wires it for the step: its label's key, whether it is
 * disabled, and either its press or the form it submits, by id, which on the details step is the
 * step's own form, so the browser's constraint validation and Enter's submission both stay the
 * form's.
 */
export interface Proceed {
    readonly label: LineKey;
    readonly disabled: boolean;
    readonly form?: string;
    readonly onPress?: () => void;
}

interface EstimateProps {
    readonly tenantName: string;
    readonly catalog: Catalog | undefined;
    readonly segments: readonly Segment[];
    readonly step: StripName;
    readonly timezone: string;
    readonly selectedDay: string | null;
    readonly chosenStart: string | null;
    readonly proceed: Proceed;
    /** A line's remove control, on the service step alone. */
    readonly onRemove?: ((index: number) => void) | undefined;
}

interface Line {
    readonly name: string;
    readonly detail: string | null;
    readonly price: string | null;
}

interface Totals {
    readonly minutes: number;
    readonly sum: string | null;
}

/** The phone bar's brief: how many services and how long they take together. */
export function briefOf(services: number, minutes: number, words: Words): string {
    return words.choice(BRIEF_LINE, services, { duration: formatDuration(minutes, words) });
}

export function Estimate({ tenantName, catalog, segments, step, timezone, selectedDay, chosenStart, proceed, onRemove }: EstimateProps): ReactNode {
    const words = useWords();
    const { text } = words;
    const labelId = useId();
    const lines = catalog === undefined ? [] : segments.map((segment) => lineOf(catalog, segment, step, words));
    const totals = catalog === undefined ? null : totalsOf(catalog, segments, words.locale);
    const when = step === 'when' || step === 'details' ? whenOf(selectedDay, chosenStart, timezone, words.locale) : null;

    return (
        <aside className={segments.length === 0 ? 'kr-booking__estimate kr-booking__estimate--empty' : 'kr-booking__estimate'} aria-labelledby={labelId}>
            <div className="kr-booking__estimate-head">
                <span className="kr-booking__estimate-disc" aria-hidden="true">
                    {initialsOf(tenantName, 1)}
                </span>
                <span className="kr-booking__estimate-business">{tenantName}</span>
            </div>
            {segments.length === 0 && <p className="kr-booking__estimate-empty">{text(PICK_A_SERVICE)}</p>}
            {lines.length > 0 && <Lines lines={lines} onRemove={onRemove} />}
            {when !== null && <p className="kr-booking__estimate-when">{when}</p>}
            {totals !== null && segments.length > 0 && <TotalLines totals={totals} services={segments.length} />}
            <p id={labelId} className="kr-booking__estimate-label">
                {text(ESTIMATE_LABEL)}
            </p>
            <button
                type={proceed.form === undefined ? 'button' : 'submit'}
                form={proceed.form}
                className="kr-booking__button kr-booking__button--primary kr-booking__continue"
                disabled={proceed.disabled}
                onClick={proceed.onPress}
            >
                {text(proceed.label)}
            </button>
        </aside>
    );
}

interface LinesProps {
    readonly lines: readonly Line[];
    readonly onRemove: ((index: number) => void) | undefined;
}

/** A line per segment, keyed by index since the same service twice is a legal chain. */
function Lines({ lines, onRemove }: LinesProps): ReactNode {
    const { text } = useWords();

    return (
        <ul className="kr-booking__list kr-booking__estimate-items">
            {lines.map((line, index) => (
                <li key={index} className="kr-booking__estimate-item">
                    <span className="kr-booking__estimate-name">{line.name}</span>
                    {line.detail !== null && <span className="kr-booking__estimate-detail">{line.detail}</span>}
                    {line.price !== null && <span className="kr-booking__estimate-price">{line.price}</span>}
                    {onRemove !== undefined && (
                        <button type="button" className="kr-booking__remove" aria-label={text(REMOVE_LABEL, { service: line.name })} onClick={() => onRemove(index)}>
                            <CrossIcon />
                        </button>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** The total line, the duration and the sum where there is one, then the phone bar's brief count. */
function TotalLines({ totals, services }: { readonly totals: Totals; readonly services: number }): ReactNode {
    const words = useWords();

    return (
        <>
            <p className="kr-booking__estimate-total">
                <span className="kr-booking__estimate-word">{words.text(ESTIMATE_TOTAL)}</span> <span className="kr-booking__estimate-duration">{formatDuration(totals.minutes, words)}</span>
                {totals.sum !== null && (
                    <>
                        {' '}
                        <span className="kr-booking__estimate-sum">{totals.sum}</span>
                    </>
                )}
            </p>
            <p className="kr-booking__estimate-brief">{briefOf(services, totals.minutes, words)}</p>
        </>
    );
}

/**
 * One segment's line: the service's name, or the placeholder; its duration with who performs it
 * from the staff step on, where its service lists anybody; its price in its own currency.
 */
function lineOf(catalog: Catalog, segment: Segment, step: StripName, words: Words): Line {
    const row = serviceById(catalog, segment.serviceId);

    if (row === undefined) {
        return { name: nameOf(catalog, segment, words), detail: null, price: null };
    }

    const duration = formatDuration(row.durationMin, words);

    return {
        name: row.name,
        detail: step === 'service' || row.staff.length === 0 ? duration : detailOf(row, segment.staffId, duration, words),
        price: formatMoney(row.priceMinor, row.currency, words.locale),
    };
}

/** A line's duration with who performs it, one whole line for each of the two cases. */
function detailOf(row: CatalogService, staffId: number | null, duration: string, words: Words): string {
    const person = staffId === null ? undefined : row.staff.find((candidate) => candidate.id === staffId);

    return person === undefined ? words.text(ANY_PROFESSIONAL_LINE, { duration }) : words.text(WITH_STAFF_LINE, { duration, staff: person.name });
}

/**
 * The two totals, summed in minor units in the one currency every listed row carries, or no sum
 * where the rows carry more than one. A segment the catalogue does not list counts in neither.
 */
function totalsOf(catalog: Catalog, segments: readonly Segment[], locale: string): Totals {
    const listed = segments.map((segment) => serviceById(catalog, segment.serviceId)).filter((row) => row !== undefined);
    const currencies = new Set(listed.map((row) => row.currency));
    const [currency] = currencies;

    return {
        minutes: listed.reduce((total, row) => total + row.durationMin, 0),
        sum: currencies.size === 1 && currency !== undefined ? formatMoney(listed.reduce((total, row) => total + row.priceMinor, 0), currency, locale) : null,
    };
}

/** The day on the tenant's clock once chosen, and the time beside it once a time is, in the page's language. */
function whenOf(selectedDay: string | null, chosenStart: string | null, timezone: string, locale: string): string | null {
    if (chosenStart !== null) {
        const wall = wallClockOf(chosenStart, timezone);

        return formatMoment(wall, locale);
    }

    return selectedDay === null ? null : formatDay(selectedDay, locale);
}
