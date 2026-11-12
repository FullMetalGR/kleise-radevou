import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { type ShellKey, useShell } from '@/lib/strings';

/*
 * Copying a booking link or the snippet to the clipboard: the button says in a line beside it
 * what happened, and where the copy carried the booking link it records the press for the setup
 * checklist, once the clipboard write has landed and never when it failed. The record is an async
 * visit that keeps the page, so a settings save in flight is not cancelled, and it says nothing
 * of its own: the line already did. The lines and the buttons' labels are keys of the shell's
 * section, read in the page's language.
 */

/** The press recorded for the setup checklist, POST /admin/settings/link-copied. */
export const LINK_COPIED = '/admin/settings/link-copied';

export const COPIED = 'copy.copied';

/** A link's copy that failed: the sentence, with the link itself beside it to select by hand. */
export const LINK_COPY_FAILED = 'copy.failed';

export const COPY_BOOKING_LINK = 'copy.booking_link';

export const COPY_LINK = 'copy.link';

export type Copy = { said: string | null; failed: boolean; copy: (text: string) => void };

/**
 * One copy control's state: what its line says, and whether the last copy failed.
 *
 * @param failure  the line a failed copy says, in the page's language
 * @param records whether a landed copy records the press: true for a booking link or the snippet
 *                copied by a viewer who may record it, false for anything else
 */
export function useCopy(failure: string, records: boolean): Copy {
    const shell = useShell();
    const [outcome, setOutcome] = useState<'copied' | 'failed' | null>(null);

    const copy = (text: string) => {
        navigator.clipboard
            .writeText(text)
            .then(() => {
                setOutcome('copied');

                if (records) {
                    router.post(LINK_COPIED, {}, { async: true, preserveState: true, preserveScroll: true });
                }
            })
            .catch(() => setOutcome('failed'));
    };

    return { said: outcome === null ? null : outcome === 'copied' ? shell.text(COPIED) : failure, failed: outcome === 'failed', copy };
}

type CopyLinkProps = {
    link: string;
    /** The button's label, a key of the shell's section: COPY_BOOKING_LINK or COPY_LINK. */
    label: ShellKey;
    /** The block the button and its line take their classes from. */
    block: string;
    records: boolean;
};

/**
 * A button copying one booking link, its line beside it: the copy said, or the failure with the
 * link to select by hand.
 */
export function CopyLink({ link, label, block, records }: CopyLinkProps) {
    const shell = useShell();
    const { said, failed, copy } = useCopy(shell.text(LINK_COPY_FAILED), records);

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <Button className={`${block}__copy`} onClick={() => copy(link)}>
                {shell.text(label)}
            </Button>
            {said !== null && (
                <span className={`${block}__copied text-sm text-muted-foreground`} role="status">
                    {said}
                    {failed && <code className="ml-1 break-all select-all">{link}</code>}
                </span>
            )}
        </span>
    );
}
