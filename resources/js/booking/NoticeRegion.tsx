import { useEffect, useState, type ReactNode } from 'react';
import type { Notice } from './api';
import { useWords, type Words } from './words';

/*
 * The one shared region every notice goes through. It always renders a polite paragraph, empty
 * when there is nothing to say, and additionally an alert paragraph when the notice is an alert,
 * in which case the polite paragraph stays empty.
 *
 * The polite paragraph mounts empty and takes its text one effect later. A live region born
 * with text announces nothing, so the insertion must be one DOM write and the text a later one,
 * which a MutationObserver can verify: the insertion record precedes the text record. The text
 * lands inside a keyed element, so that two consecutive identical notices each produce a fresh
 * DOM write into the region rather than a render React can skip, or a screen reader hears only
 * the first. A notice is re-announced when its object changes, so callers hold notices in state
 * or memo and hand the same one down until it changes.
 *
 * An alert announces on appearance, so it renders with its text at once.
 *
 * A notice of the app's own is read through the words at render, in the language the app holds, so
 * the switch re-renders it in the new language; a sentence the server wrote is shown as it came.
 */

interface Shown {
    readonly notice: Notice;
    readonly seq: number;
}

/** What a notice says: the server's sentence as it came, or the app's own line for its key, filled. */
function textOf(notice: Notice, words: Words): string {
    return 'key' in notice ? words.text(notice.key, notice.values) : notice.text;
}

interface NoticeRegionProps {
    readonly notice: Notice | null;
}

export function NoticeRegion({ notice }: NoticeRegionProps): ReactNode {
    const words = useWords();
    const [shown, setShown] = useState<Shown | null>(null);

    useEffect(() => {
        if (notice === null || notice.tone === 'alert') {
            setShown(null);

            return;
        }

        setShown((previous) => ({ notice, seq: (previous?.seq ?? 0) + 1 }));
    }, [notice]);

    return (
        <>
            <p className="kr-booking__notice kr-booking__notice--polite" role="status">
                {shown !== null && <span key={shown.seq}>{textOf(shown.notice, words)}</span>}
            </p>
            {notice !== null && notice.tone === 'alert' && (
                <p className="kr-booking__notice kr-booking__notice--error" role="alert">
                    {textOf(notice, words)}
                </p>
            )}
        </>
    );
}
