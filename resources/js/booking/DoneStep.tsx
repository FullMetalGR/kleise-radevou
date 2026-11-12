import { useMemo, type ReactNode } from 'react';
import type { Notice } from './api';
import { CheckIcon } from './icons';
import { NoticeRegion } from './NoticeRegion';
import type { LineKey } from './words';

/*
 * Step done: a panel tinted in the success colour holding a success disc, a check hidden from
 * assistive technology since the sentence says it, then one paragraph, mounted empty and
 * filled one effect later, which is what the shared notice region does and why the outcome
 * goes through it. A live region born with text announces nothing.
 *
 * The sentence is chosen by the server's returned status, never by the catalog's approval
 * flag, which can disagree with what the write actually did. "Request sent" and "confirmed" are
 * deliberately different screens and must never be flattened into one. A confirmed booking the
 * hold said is paid at the venue says so in its sentence; no other status changes for it.
 *
 * The frame's title on this step is its heading, chosen by the same returned status alone, so
 * the heading cannot say confirmed over a request still waiting on a human either.
 *
 * done is terminal: no Back, no further controls.
 */

interface DoneStepProps {
    readonly status: string;
    readonly atVenue?: boolean;
}

export const RECEIVED_SENTENCE: LineKey = 'done.sentences.received';

const SENTENCES: Readonly<Record<string, LineKey>> = {
    confirmed: 'done.sentences.confirmed',
    awaiting_approval: 'done.sentences.awaiting_approval',
    awaiting_payment: 'done.sentences.awaiting_payment',
};

/** The key of the done step's sentence by the returned status, and the venue's for a confirmed booking paid there. */
export function sentenceFor(status: string, atVenue?: boolean): LineKey {
    return atVenue && status === 'confirmed' ? 'done.sentences.at_venue' : (SENTENCES[status] ?? RECEIVED_SENTENCE);
}

export const RECEIVED_HEADING: LineKey = 'done.headings.received';

const HEADINGS: Readonly<Record<string, LineKey>> = {
    confirmed: 'done.headings.confirmed',
    awaiting_approval: 'done.headings.awaiting_approval',
    awaiting_payment: 'done.headings.awaiting_payment',
};

/** The key of the done step's heading, which the frame renders as its title, by the returned status alone. */
export function headingFor(status: string): LineKey {
    return HEADINGS[status] ?? RECEIVED_HEADING;
}

export function DoneStep({ status, atVenue }: DoneStepProps): ReactNode {
    const outcome = useMemo<Notice>(() => ({ key: sentenceFor(status, atVenue), tone: 'polite' }), [status, atVenue]);

    return (
        <div className="kr-booking__step kr-booking__done kr-booking__notice--success">
            <span className="kr-booking__success" aria-hidden="true">
                <CheckIcon />
            </span>
            <NoticeRegion notice={outcome} />
        </div>
    );
}
