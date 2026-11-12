import { useEffect, useMemo, useRef, useState, type ReactNode, type RefObject } from 'react';
import { classify, type Notice } from './api';
import { categoryGroups, OTHER_HEADING, serviceById, type Catalog, type CatalogService } from './catalog';
import { formatDuration } from './duration';
import type { Segment } from './flow';
import { CheckIcon, PlusIcon } from './icons';
import { formatMoney } from './money';
import { NoticeRegion } from './NoticeRegion';
import { useWords, type LineKey, type Words } from './words';

/*
 * Step service. The services are cards that toggle in place: pressing an unpressed card appends a
 * segment and pressing a pressed one removes that service's segment, the first where two exist,
 * and neither advances. The chosen services are stated in the summary card beside the step, which
 * carries the one Continue and a remove control per line; the step holds no chain builder and no
 * Continue of its own. At the chain's maximum the unpressed cards are disabled, and one sentence
 * above the list says how many can be chosen.
 *
 * The announcement region is always present on the step, empty until a removal, whichever control
 * removed. Its notice is the flow's, through useRemoval, because the summary card removes too; it
 * is cleared once the step is left, so a return to the step mounts it empty. A pressed card stays
 * mounted and keeps its focus; a remove control of the summary card unmounts with its line, so
 * focus is re-seated after that commit, since segments key by index.
 */

/**
 * What the picker needs to know of the catalog query: the data, kept across a failed
 * refetch, whether it is still waiting for a first answer, and the error if any.
 */
export interface CatalogState {
    readonly data: Catalog | undefined;
    readonly error: unknown;
    readonly isPending: boolean;
}

interface ServiceStepProps {
    readonly catalog: CatalogState;
    readonly segments: readonly Segment[];
    /** The removal announcement of 2.1, the flow's, since the summary card removes too. */
    readonly announcement: Notice | null;
    readonly onAppend: (service: CatalogService) => void;
    /** A press on a pressed card: the segment at the index goes, and the card keeps its focus. */
    readonly onRemove: (index: number) => void;
}

export const EMPTY_SENTENCE: LineKey = 'service.empty';

export const STALE_SENTENCE: LineKey = 'service.stale';

export const PLACEHOLDER_NAME: LineKey = 'service.placeholder';

export const CHIPS_LABEL: LineKey = 'service.chips';

export const LOADING_SERVICES_SENTENCE: LineKey = 'service.loading';

/**
 * The sentence above the list at the chain's maximum, saying how many can be chosen, read as a
 * choice by the maximum.
 */
export const LIMIT_SENTENCE: LineKey = 'service.limit';

/** What a removal announces, its :service the removed service's name. */
export const REMOVED_SENTENCE: LineKey = 'service.removed';

/** How many blank cards stand in for the list while it loads. */
const SKELETON_ROWS = [0, 1, 2];

/** The fewest headings that earn a row of jump chips above the list. */
const CHIPS_FROM = 2;

const STALE_NOTICE: Notice = { key: STALE_SENTENCE, tone: 'polite' };

const REMOVE_SELECTOR = '.kr-booking__remove';
const CARD_SELECTOR = '.kr-booking__service';

export function ServiceStep({ catalog, segments, announcement, onAppend, onRemove }: ServiceStepProps): ReactNode {
    const maxChainLength = catalog.data?.maxChainLength ?? 0;
    const atMax = catalog.data !== undefined && segments.length >= maxChainLength;
    const chosen = new Set(segments.map((segment) => segment.serviceId));

    function toggle(service: CatalogService): void {
        const index = segments.findIndex((segment) => segment.serviceId === service.id);

        if (index === -1) {
            onAppend(service);
        } else {
            onRemove(index);
        }
    }

    return (
        <div className="kr-booking__step">
            <ServicePicker catalog={catalog} chosen={chosen} atMax={atMax} maxChainLength={maxChainLength} onToggle={toggle} />
            <NoticeRegion notice={announcement} />
        </div>
    );
}

/** The service a segment names, or the placeholder when the catalog does not list it. */
export function nameOf(catalog: Catalog | undefined, segment: Segment | undefined, words: Words): string {
    return (segment === undefined ? undefined : serviceById(catalog, segment.serviceId)?.name) ?? words.text(PLACEHOLDER_NAME);
}

/**
 * The removal rules of 2.1, held by the flow because two controls remove: a pressed card on the
 * step and a line's remove control in the summary card. Every removal announces the service's
 * name removed, one whole line with the name as its placeholder, in a fresh notice, so two
 * identical removals are two DOM writes. A removal by the summary card's control re-seats focus
 * after the commit, inside the given root, onto the remove control
 * now at that index, else the last one, else the first card of the picker; a removal by a card
 * leaves focus where it is, on the card, which stays mounted. The notice is cleared once the
 * service step is left.
 */
export interface Removal {
    readonly announcement: Notice | null;
    readonly remove: (index: number, reseat: boolean) => void;
}

export function useRemoval(root: RefObject<HTMLElement | null>, catalog: Catalog | undefined, segments: readonly Segment[], onService: boolean, onRemove: (index: number) => void): Removal {
    const words = useWords();
    const seat = useRef<number | null>(null);
    const [announcement, setAnnouncement] = useState<Notice | null>(null);

    useEffect(() => {
        const index = seat.current;

        if (index === null || root.current === null) {
            return;
        }

        seat.current = null;

        const removes = Array.from(root.current.querySelectorAll<HTMLElement>(REMOVE_SELECTOR));
        const target = removes[index] ?? removes[removes.length - 1] ?? root.current.querySelector<HTMLElement>(CARD_SELECTOR);

        target?.focus();
    });

    useEffect(() => {
        if (!onService) {
            setAnnouncement(null);
        }
    }, [onService]);

    return {
        announcement,
        remove: (index, reseat) => {
            seat.current = reseat ? index : null;
            setAnnouncement({ key: REMOVED_SENTENCE, values: { service: nameOf(catalog, segments[index], words) }, tone: 'polite' });
            onRemove(index);
        },
    };
}

interface ServicePickerProps {
    readonly catalog: CatalogState;
    readonly chosen: ReadonlySet<number>;
    readonly atMax: boolean;
    readonly maxChainLength: number;
    readonly onToggle: (service: CatalogService) => void;
}

/**
 * The picker states in precedence order: loading; error with no data; empty catalog, with no
 * list element at all; otherwise the list, under a polite stale line when a refetch failed. The
 * list is never destroyed by a failed refetch. Loading is the sentence and, under it, a few
 * blank cards shaped like the list it waits for, hidden from assistive technology, which hears
 * the sentence.
 */
function ServicePicker({ catalog, chosen, atMax, maxChainLength, onToggle }: ServicePickerProps): ReactNode {
    const { text, choice } = useWords();
    const { data, error, isPending } = catalog;
    const notice = useMemo<Notice | null>(() => {
        if (error === null || error === undefined) {
            return null;
        }

        return data === undefined ? classify(error) : STALE_NOTICE;
    }, [error, data]);

    return (
        <div className="kr-booking__picker" aria-busy={isPending}>
            <NoticeRegion notice={notice} />
            {isPending && <p className="kr-booking__notice">{text(LOADING_SERVICES_SENTENCE)}</p>}
            {isPending && (
                <div className="kr-booking__skeleton" aria-hidden="true">
                    {SKELETON_ROWS.map((row) => (
                        <span key={row} className="kr-booking__blank" />
                    ))}
                </div>
            )}
            {data !== undefined && data.services.length === 0 && <p className="kr-booking__notice">{text(EMPTY_SENTENCE)}</p>}
            {data !== undefined && data.services.length > 0 && <ServiceList services={data.services} cards={{ chosen, atMax, onToggle }} limit={atMax ? choice(LIMIT_SENTENCE, maxChainLength) : null} />}
        </div>
    );
}

/** What every card of a list needs: which services are chosen, whether the chain is full, the press. */
interface CardState {
    readonly chosen: ReadonlySet<number>;
    readonly atMax: boolean;
    readonly onToggle: (service: CatalogService) => void;
}

interface ServiceListProps {
    readonly services: readonly CatalogService[];
    readonly cards: CardState;
    /** The sentence above the list at the chain's maximum, null below it. */
    readonly limit?: string | null;
}

/**
 * The cards, under a heading per category when the catalogue has more than one group, Other
 * last, and one plain list when it has one or none (categoryGroups). From two headings up, a row
 * of jump chips above the list, one button per heading, moves focus and scroll to its heading,
 * which takes focus without entering the tab order; the chip last pressed is the pressed one,
 * the first at mount. Every card is the same button whether it sits under a heading or not, so
 * choosing and the focus rules are unchanged.
 */
function ServiceList({ services, cards, limit = null }: ServiceListProps): ReactNode {
    const { text } = useWords();
    const headings = useRef<(HTMLHeadingElement | null)[]>([]);
    const [current, setCurrent] = useState(0);
    const groups = categoryGroups(services, text(OTHER_HEADING));
    const sentence = limit === null ? null : <p className="kr-booking__limit">{limit}</p>;

    if (groups === null) {
        return (
            <>
                {sentence}
                <ServiceCards services={services} cards={cards} />
            </>
        );
    }

    function jump(index: number): void {
        const heading = headings.current[index];

        setCurrent(index);
        heading?.focus({ preventScroll: true });
        heading?.scrollIntoView({ block: 'start' });
    }

    return (
        <>
            {sentence}
            {groups.length >= CHIPS_FROM && (
                <nav className="kr-booking__chips" aria-label={text(CHIPS_LABEL)}>
                    {groups.map((group, index) => (
                        <button key={group.heading} type="button" className="kr-booking__chip" aria-pressed={index === current} onClick={() => jump(index)}>
                            {group.heading}
                        </button>
                    ))}
                </nav>
            )}
            {groups.map((group, index) => (
                <div key={group.heading} className="kr-booking__group">
                    <h3
                        className="kr-booking__subtitle kr-booking__group-heading"
                        tabIndex={-1}
                        ref={(heading) => {
                            headings.current[index] = heading;
                        }}
                    >
                        {group.heading}
                    </h3>
                    <ServiceCards services={group.services} cards={cards} />
                </div>
            ))}
        </>
    );
}

function ServiceCards({ services, cards }: Omit<ServiceListProps, 'limit'>): ReactNode {
    return (
        <ul className="kr-booking__list kr-booking__services">
            {services.map((service) => (
                <li key={service.id} className="kr-booking__item">
                    <ServiceCard service={service} pressed={cards.chosen.has(service.id)} full={cards.atMax} onToggle={cards.onToggle} />
                </li>
            ))}
        </ul>
    );
}

interface ServiceCardProps {
    readonly service: CatalogService;
    readonly pressed: boolean;
    /** The chain is at its maximum, which disables a card not already chosen. */
    readonly full: boolean;
    readonly onToggle: (service: CatalogService) => void;
}

/**
 * A card is one button stating in aria-pressed whether its service is in the chain, holding in
 * order the name, the duration, the description where set and the price in the row's own
 * currency, so its accessible name is those four; then the round mark drawn at the right, a plus
 * or, pressed, a check, hidden from assistive technology since the pressed state says it.
 */
function ServiceCard({ service, pressed, full, onToggle }: ServiceCardProps): ReactNode {
    const words = useWords();

    return (
        <button type="button" className="kr-booking__service" aria-pressed={pressed} disabled={full && !pressed} onClick={() => onToggle(service)}>
            <span className="kr-booking__service-text">
                <span className="kr-booking__service-name">{service.name}</span>
                <span className="kr-booking__service-duration">{formatDuration(service.durationMin, words)}</span>
                {service.description !== '' && <span className="kr-booking__service-description">{service.description}</span>}
                <span className="kr-booking__service-price">{formatMoney(service.priceMinor, service.currency, words.locale)}</span>
            </span>
            <span className="kr-booking__service-mark" aria-hidden="true">
                {pressed ? <CheckIcon /> : <PlusIcon />}
            </span>
        </button>
    );
}
