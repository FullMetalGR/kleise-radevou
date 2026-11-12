import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import type { Notice } from './api';
import { formatRemaining, milestoneOf, remainingSeconds, untilNextTick } from './countdown';
import { NoticeRegion } from './NoticeRegion';
import { useWords, type LineKey } from './words';

/*
 * The countdown: the first thing on the review step when the server returned an expiry, and
 * nothing at all when it did not, which the step decides.
 *
 * The reading ticks once a second against the server's deadline, each tick landing where the
 * ceiling steps, and stops at zero. It sits deliberately outside any live region: a per-second
 * announcement is hostile. The milestones go through the shared notice region, polite, which
 * exists from the first paint mounted empty and takes its text one effect later; the notice is
 * memoised on its sentence so a tick that leaves the sentence where it was announces nothing
 * again.
 *
 * Expiry fires exactly once, guarded by a ref, whether zero is reached by ticking or was already
 * behind a component mounted with a past deadline. The callback is read through a ref so a flow
 * that hands down a new one on every render neither refires nor restarts the clock.
 *
 * The expiry flag is one flag with two ways up (4.2): the countdown's own zero, or the server's
 * 410 while the clock still reads time left. Whichever raised it, the countdown follows it: once
 * the flow says the hold is gone, it reads 0:00, stops ticking, fires nothing and announces
 * nothing further. Its near sentence is emptied rather than replaced, because the refusal that
 * raised the flag says the hold is gone once its recovery ends, and a second sentence would
 * announce it twice. A countdown that reached zero itself keeps its own run-out sentence.
 *
 * The browser clock is read here and only here on the review step, and only as the now it was
 * handed, so a test can hand it a clock of its own.
 */

/** The label beside the reading. */
export const COUNTDOWN_LABEL: LineKey = 'countdown.label';

interface CountdownProps {
    /** The server's deadline, as the wire spells it. */
    readonly deadline: string;
    readonly now: () => number;
    /** The flow's expiry flag, whichever way it went up. */
    readonly expired: boolean;
    readonly onExpire: () => void;
}

interface Reading {
    readonly seconds: number;
}

export function Countdown({ deadline, now, expired, onExpire }: CountdownProps): ReactNode {
    const { text: say } = useWords();
    // A fresh object every tick, so a tick that lands early and reads the same second still
    // re-arms the next one rather than leaving the clock stopped.
    const [reading, setReading] = useState<Reading>(() => ({ seconds: remainingSeconds(deadline, now()) }));
    const fired = useRef(false);
    const expire = useRef(onExpire);
    // Told the hold is gone before its own zero: run out, and nothing more to say.
    const told = expired && reading.seconds > 0;
    const seconds = told ? 0 : reading.seconds;
    const milestone = told ? null : milestoneOf(seconds);
    const notice = useMemo<Notice | null>(() => (milestone === null ? null : { key: milestone, tone: 'polite' }), [milestone]);

    useEffect(() => {
        expire.current = onExpire;
    }, [onExpire]);

    useEffect(() => {
        if (told) {
            return;
        }

        if (reading.seconds === 0) {
            if (!fired.current) {
                fired.current = true;
                expire.current();
            }

            return;
        }

        const timer = setTimeout(() => setReading({ seconds: remainingSeconds(deadline, now()) }), untilNextTick(deadline, now()));

        return () => clearTimeout(timer);
    }, [reading, deadline, now, told]);

    return (
        <div className="kr-booking__countdown">
            <p className="kr-booking__countdown-reading">
                {say(COUNTDOWN_LABEL)} <span className="kr-booking__countdown-value">{formatRemaining(seconds)}</span>
            </p>
            <NoticeRegion notice={notice} />
        </div>
    );
}
