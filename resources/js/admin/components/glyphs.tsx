import { Check, ChevronDown, ChevronLeft, ChevronRight, CircleAlert, Plus as PlusIcon, StickyNote } from 'lucide-react';

/*
 * The admin's small glyphs, the Calendar's and Shifts', drawn by the one icon set every screen
 * draws from, as inline SVG in the text's colour: the admin's build refuses a character outside
 * ASCII in a built asset, so a caret, a chevron, a plus, a tick or an alert is never a glyph of a
 * font. Each is decoration, hidden from assistive technology; the control it sits in carries the
 * name.
 */

/** The caret of a control that opens a list under it. */
export function Caret() {
    return <ChevronDown className="size-3.5 shrink-0" aria-hidden="true" />;
}

/** The chevron at the right of a select, a little larger than a caret, as a field is taller. */
export function SelectChevron() {
    return <ChevronDown className="size-4 shrink-0" aria-hidden="true" />;
}

/** A chevron pointing back or on, Previous's and Next's face. */
export function Chevron({ to }: { to: 'previous' | 'next' }) {
    const Icon = to === 'previous' ? ChevronLeft : ChevronRight;

    return <Icon className="size-4.5" aria-hidden="true" />;
}

/** The tick beside the chosen entry of a list. */
export function Tick() {
    return <Check className="size-4 shrink-0" aria-hidden="true" />;
}

/** The circled exclamation mark an error line starts with, so the error never rests on its colour. */
export function Alert() {
    return <CircleAlert className="size-4 shrink-0" aria-hidden="true" />;
}

/** A sheet with a folded corner, the mark of a booking the customer wrote a note on. */
export function NoteSheet() {
    return <StickyNote className="size-3 shrink-0" aria-hidden="true" />;
}

/** The plus of a control that adds a row, a pattern day's second window. */
export function Plus() {
    return <PlusIcon className="size-4" aria-hidden="true" />;
}
