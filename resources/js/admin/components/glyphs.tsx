/*
 * The admin's small glyphs, the Calendar's and Shifts', drawn as inline SVG in the text's colour:
 * the admin's build refuses a character outside ASCII in a built asset, so a caret, a chevron, a
 * plus or a tick is never a glyph of a font. Each is decoration; the control it sits in carries
 * the name.
 */

/** The caret of a control that opens a list under it. */
export function Caret() {
    return (
        <svg viewBox="0 0 16 16" className="size-3 shrink-0" aria-hidden="true" fill="currentColor">
            <path d="M3.5 6h9L8 11z" />
        </svg>
    );
}

/** A chevron pointing back or on, Previous's and Next's face. */
export function Chevron({ to }: { to: 'previous' | 'next' }) {
    return (
        <svg viewBox="0 0 16 16" className="size-4" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
            <path d={to === 'previous' ? 'M10 3.5 5.5 8l4.5 4.5' : 'M6 3.5 10.5 8 6 12.5'} />
        </svg>
    );
}

/** The tick beside the chosen entry of a list. */
export function Tick() {
    return (
        <svg viewBox="0 0 16 16" className="size-4" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3.5 8.5l3 3 6-7" />
        </svg>
    );
}

/** The plus of a control that adds a row, a pattern day's second window. */
export function Plus() {
    return (
        <svg viewBox="0 0 16 16" className="size-4" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
            <path d="M8 3.5v9M3.5 8h9" />
        </svg>
    );
}
