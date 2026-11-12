import { useId, useMemo, useState, type ReactNode } from 'react';
import { classify, type Notice } from './api';
import { serviceById, type Catalog, type CatalogStaff } from './catalog';
import type { Segment } from './flow';
import { CheckIcon, ShuffleIcon } from './icons';
import { initialsOf } from './initials';
import { NoticeRegion } from './NoticeRegion';
import { LOADING_SERVICES_SENTENCE, type CatalogState } from './ServiceStep';
import { useWords, type LineKey } from './words';

/*
 * Step staff: one picker per segment whose service lists staff, and nothing for a segment whose
 * service lists nobody. "Any professional" first, as the default, reporting null, which reaches
 * the wire as an explicit null meaning any; a concrete id only when the customer pressed a
 * named person. Segments key by index, because the same service twice is a legal chain and no
 * stable id exists. The auto-skip to when, once the catalog has answered and no segment lists
 * staff, is the flow's effect, not this step's; the step's Continue is the summary card's,
 * enabled once the catalog has answered or failed.
 *
 * The choices are a grid of cards, each one button: a disc, the name and a line under it, and
 * on the pressed card a small check at its corner beside the accent border, drawn for sight
 * alone since aria-pressed says it. The first card's disc holds crossed arrows and its line
 * says what choosing nobody buys, "for maximum availability". A named card carries the person's
 * photo, or their initials in the disc where none, then the name and the title. The face is
 * decoration beside the name: the photo's alt is empty and the disc is hidden from assistive
 * technology with its initials drawn by the stylesheet from an attribute, so the choice's text
 * is the name and the title alone. The bio sits behind View profile, a secondary pill of its
 * own beside the card, never inside it, which discloses the bio under the card and reads Hide
 * profile while open; it presses nobody and moves no focus. A person with no bio has no View
 * profile.
 */

/** The first choice of every picker, which reaches the wire as a null staff_id. */
export const ANY_PROFESSIONAL: LineKey = 'staff.any';

/** The line under the first choice's name: what choosing nobody in particular buys. */
export const MAXIMUM_AVAILABILITY: LineKey = 'staff.any_line';

export const VIEW_PROFILE: LineKey = 'staff.view_profile';

export const HIDE_PROFILE: LineKey = 'staff.hide_profile';

interface StaffStepProps {
    readonly catalog: CatalogState;
    readonly segments: readonly Segment[];
    readonly onChoose: (index: number, staffId: number | null) => void;
}

export function StaffStep({ catalog, segments, onChoose }: StaffStepProps): ReactNode {
    const { text } = useWords();
    const { data, error, isPending } = catalog;
    const notice = useMemo<Notice | null>(() => (data === undefined && error !== null && error !== undefined ? classify(error) : null), [error, data]);

    return (
        <div className="kr-booking__step" aria-busy={isPending}>
            <NoticeRegion notice={notice} />
            {isPending && <p className="kr-booking__notice">{text(LOADING_SERVICES_SENTENCE)}</p>}
            {segments.map((segment, index) => (
                <StaffPicker key={index} catalog={data} segment={segment} onChoose={(staffId) => onChoose(index, staffId)} />
            ))}
        </div>
    );
}

interface StaffPickerProps {
    readonly catalog: Catalog | undefined;
    readonly segment: Segment;
    readonly onChoose: (staffId: number | null) => void;
}

function StaffPicker({ catalog, segment, onChoose }: StaffPickerProps): ReactNode {
    const { text } = useWords();
    const headingId = useId();
    const service = serviceById(catalog, segment.serviceId);

    if (service === undefined || service.staff.length === 0) {
        return null;
    }

    return (
        <div className="kr-booking__staff" role="group" aria-labelledby={headingId}>
            <h3 className="kr-booking__subtitle" id={headingId}>
                {service.name}
            </h3>
            <ul className="kr-booking__list kr-booking__people">
                <li className="kr-booking__item kr-booking__person">
                    <button type="button" className="kr-booking__choice" aria-pressed={segment.staffId === null} onClick={() => onChoose(null)}>
                        {segment.staffId === null && <ChoiceMark />}
                        <span className="kr-booking__face" aria-hidden="true">
                            <ShuffleIcon />
                        </span>
                        <span className="kr-booking__person-name">{text(ANY_PROFESSIONAL)}</span> <span className="kr-booking__person-title">{text(MAXIMUM_AVAILABILITY)}</span>
                    </button>
                </li>
                {service.staff.map((person: CatalogStaff) => (
                    <PersonRow key={person.id} person={person} pressed={segment.staffId === person.id} onPress={() => onChoose(person.id)} />
                ))}
            </ul>
        </div>
    );
}

interface PersonRowProps {
    readonly person: CatalogStaff;
    readonly pressed: boolean;
    readonly onPress: () => void;
}

function PersonRow({ person, pressed, onPress }: PersonRowProps): ReactNode {
    const { text } = useWords();
    const bioId = useId();
    const [open, setOpen] = useState(false);

    return (
        <li className="kr-booking__item kr-booking__person">
            <button type="button" className="kr-booking__choice" aria-pressed={pressed} onClick={onPress}>
                {pressed && <ChoiceMark />}
                {person.photoUrl === null ? (
                    <span className="kr-booking__face" data-initials={initialsOf(person.name)} aria-hidden="true" />
                ) : (
                    <img className="kr-booking__face" src={person.photoUrl} alt="" />
                )}
                <span className="kr-booking__person-name">{person.name}</span>
                {person.title !== null && (
                    <>
                        {' '}
                        <span className="kr-booking__person-title">{person.title}</span>
                    </>
                )}
            </button>
            {person.bio !== null && (
                <button type="button" className="kr-booking__profile" aria-expanded={open} aria-controls={open ? bioId : undefined} onClick={() => setOpen(!open)}>
                    {text(open ? HIDE_PROFILE : VIEW_PROFILE)}
                </button>
            )}
            {open && (
                <p className="kr-booking__bio" id={bioId}>
                    {person.bio}
                </p>
            )}
        </li>
    );
}

/** The check at a pressed card's corner, for sight alone: the pressed state is the button's own. */
function ChoiceMark(): ReactNode {
    return (
        <span className="kr-booking__choice-mark" aria-hidden="true">
            <CheckIcon />
        </span>
    );
}
