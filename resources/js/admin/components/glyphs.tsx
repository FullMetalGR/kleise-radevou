import {
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    Clock,
    CreditCard,
    Hourglass,
    Info as InfoIcon,
    LogOut,
    Moon as MoonIcon,
    Plus as PlusIcon,
    StickyNote,
    Sun as SunIcon,
    Timer,
    TriangleAlert,
    X,
} from 'lucide-react';

/*
 * The admin's small glyphs, the Calendar's and Shifts', the notice's, the shell's and the status
 * chip's, drawn by the one icon set every screen draws from, as inline SVG in the text's colour:
 * the admin's build refuses a character outside ASCII in a built asset, so a caret, a chevron, a
 * plus, a tick, a cross, an alert, a moon or a sun is never a glyph of a font. Each is decoration,
 * hidden from assistive technology; the control or the words it sits beside carry the name.
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

/** The cross of a control that closes or dismisses what it sits in. */
export function Cross() {
    return <X className="size-4 shrink-0" aria-hidden="true" />;
}

/** The circled i a notice that informs starts with. */
export function Info() {
    return <InfoIcon className="size-4 shrink-0" aria-hidden="true" />;
}

/** The warning triangle a notice that warns starts with. */
export function Warning() {
    return <TriangleAlert className="size-4 shrink-0" aria-hidden="true" />;
}

/** The circled tick a notice of something done starts with. */
export function Success() {
    return <CircleCheck className="size-4 shrink-0" aria-hidden="true" />;
}

/** The moon on the theme switch while the page is light, the dark theme it switches to. */
export function Moon() {
    return <MoonIcon className="size-5" aria-hidden="true" />;
}

/** The sun on the theme switch while the page is dark, the light theme it switches to. */
export function Sun() {
    return <SunIcon className="size-5" aria-hidden="true" />;
}

/** The door with an arrow leaving it, beside Sign out. */
export function SignOut() {
    return <LogOut className="size-4.5" aria-hidden="true" />;
}

/*
 * A status chip's marks, drawn at 13px to sit beside the chip's 13px label, a size under the
 * glyphs above: the clock of a booking awaiting the operator, the tick of one confirmed or done,
 * the card of one awaiting payment, the hourglass of one held, the cross of one cancelled or
 * rejected, the alert of a no-show and the timer of one that ran out.
 */

export function ChipClock() {
    return <Clock className="size-3.25 shrink-0" aria-hidden="true" />;
}

export function ChipTick() {
    return <Check className="size-3.25 shrink-0" aria-hidden="true" />;
}

export function ChipCard() {
    return <CreditCard className="size-3.25 shrink-0" aria-hidden="true" />;
}

export function ChipHourglass() {
    return <Hourglass className="size-3.25 shrink-0" aria-hidden="true" />;
}

export function ChipCross() {
    return <X className="size-3.25 shrink-0" aria-hidden="true" />;
}

export function ChipAlert() {
    return <CircleAlert className="size-3.25 shrink-0" aria-hidden="true" />;
}

export function ChipTimer() {
    return <Timer className="size-3.25 shrink-0" aria-hidden="true" />;
}
