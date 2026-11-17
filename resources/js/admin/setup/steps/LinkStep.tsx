import { router } from '@inertiajs/react';
import { useState } from 'react';
import { COPY_BOOKING_LINK, CopyLink } from '@/lib/copy';
import { useShell } from '@/lib/strings';
import { NOT_DONE, owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import { useStepMoves, useStepPage, useStepPrimary } from '../step';

/*
 * The Booking link step: the hosted booking page's address, Copy, Open booking page in a new tab,
 * and Finish. Copy here puts the link on the clipboard and records nothing, so the step stays
 * open under the owner until Finish, which alone records it and, once that has landed, closes the
 * wizard; where it closed the last open step Home answers with no setup and the word that setup
 * was finished just now, and the wizard says setup is complete as it closes.
 */

/** Finish, POST /admin/setup/finish, body-less. */
export const FINISH_URL = '/admin/setup/finish';

type LinkData = { hosted_url: string };

type LinkStrings = { name: string; help: string; open: string; finish: string; finishing: string };

export function LinkStep() {
    const { data, words } = useStepPage<LinkData, LinkStrings>();
    const { close } = useStepMoves();
    const shell = useShell();
    const toasts = useToasts();
    const [finishing, setFinishing] = useState(false);

    const finish = () => {
        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(
            FINISH_URL,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    setFinishing(true);
                    owned.onStart();
                },
                onSuccess: close,
                onFinish: () => {
                    owned.onFinish();
                    setFinishing(false);
                },
            },
        );
    };

    useStepPrimary({ label: words.text('finish'), onPress: finish, busy: finishing ? words.text('finishing') : null });

    if (data === null) {
        return null;
    }

    return (
        <div className="admin-setup-link flex flex-col gap-3">
            <p className="admin-setup-link__help text-sm text-muted-foreground">{words.text('help')}</p>
            <a className="admin-setup-link__url text-sm break-all underline" href={data.hosted_url} target="_blank" rel="noreferrer">
                {data.hosted_url}
            </a>
            <span className="flex flex-wrap items-center gap-3">
                <CopyLink link={data.hosted_url} label={COPY_BOOKING_LINK} block="admin-setup-link" records={false} />
                <a className="admin-setup-link__open text-sm underline" href={data.hosted_url} target="_blank" rel="noreferrer">
                    {words.text('open')}
                </a>
            </span>
        </div>
    );
}
