import type { ReactNode } from 'react';

/*
 * The booking app's icons: inline stroke drawings in the markup, in the current text colour,
 * hidden from assistive technology and never focusable, so the control holding one is named by
 * its own words or its aria-label and nothing here is ever read. Never a data URI in the
 * stylesheet, never an icon font, never a character standing in for a picture. The size is the
 * stylesheet's, through the class every drawing carries.
 */

interface DrawingProps {
    readonly children: ReactNode;
    readonly weight?: number;
}

function Drawing({ children, weight = 2 }: DrawingProps): ReactNode {
    return (
        <svg className="kr-booking__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={weight} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
            {children}
        </svg>
    );
}

/** A plus: a service card not yet chosen. */
export function PlusIcon(): ReactNode {
    return (
        <Drawing>
            <path d="M12 5v14M5 12h14" />
        </Drawing>
    );
}

/** A check: a service card chosen. */
export function CheckIcon(): ReactNode {
    return (
        <Drawing weight={2.4}>
            <path d="M5 12l5 5L19 7" />
        </Drawing>
    );
}

/** A cross: a line's remove control in the summary card. */
export function CrossIcon(): ReactNode {
    return (
        <Drawing weight={2.2}>
            <path d="M6 6l12 12M18 6L6 18" />
        </Drawing>
    );
}

/** A chevron pointing back: the crumb's back control. */
export function BackIcon(): ReactNode {
    return (
        <Drawing>
            <path d="M15 6l-6 6 6 6" />
        </Drawing>
    );
}

/** A chevron pointing on: the separator between the crumb's steps. */
export function OnwardIcon(): ReactNode {
    return (
        <Drawing>
            <path d="M9 6l6 6-6 6" />
        </Drawing>
    );
}

/** Crossed arrows: the staff step's "Any professional" disc. */
export function ShuffleIcon(): ReactNode {
    return (
        <Drawing weight={1.8}>
            <path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5" />
        </Drawing>
    );
}
