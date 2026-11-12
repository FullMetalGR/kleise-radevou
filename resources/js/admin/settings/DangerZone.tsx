import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Confirm } from '@/components/ui/confirm';
import { useDownload } from '@/lib/download';
import { useShell } from '@/lib/strings';
import { owning, owningFailure } from '@/lib/visits';
import { type BillingKey, useBilling } from '@/settings/words';
import { useToasts } from '@/toast/toasts';

/*
 * The Settings Danger zone, the last section on every plan, on the pattern of the staff editor's:
 * outside the save bar's form, since nothing in it is saved, and rendered by the page only for a
 * viewer holding manageSettings.
 *
 * Export everything is a secondary button with one sentence saying what the archive holds, which
 * downloads GET /admin/settings/export through the export buttons' one road (lib/download.ts):
 * busy while the request is on its way, the zip saved under the name the server gave it, the
 * tenant's slug and today, and a failure an error toast.
 *
 * Close this account sits under it, a danger button with one sentence beside it, opening the kit's
 * one confirm. The dialog says, from the server's close prop and never the browser's clock, that
 * the account closes in thirty days, on the day the server spelled in the tenant's clock with the
 * zone named, and everything in it is deleted then; what keeps running and what pauses; that
 * billing stops now; and the way back the server words, a plan on the Billing screen or, for a free
 * tenant, support. Nothing is optimistic: the confirm is busy until the server answers, a landed
 * close leaves on the server's redirect with its flash, and a refusal, a 409 from a close made
 * elsewhere since the page was read among them, is its sentence in an error toast with the dialog
 * left as it stood, the page then reading the close prop again, whose closing sentence takes the
 * control's place where the account closed meanwhile. While the account is closing the control is
 * replaced by the server's sentence, the one the Billing screen's notice carries. A support session
 * is offered no close, which the server refuses it, and sees the closing sentence alone.
 *
 * Its words are keys of the billing section, which SettingsController names beside the settings
 * section, read through the words the page provides: each constant holds its key and keeps its
 * name. The window's days are the server's, the close prop's window, read as a choice by the count
 * and spelled in words by ICU in the user's language.
 */

/** The tenant export. */
export const EXPORT_PATH = '/admin/settings/export';

export const EXPORT_EVERYTHING = 'danger.export.button' satisfies BillingKey;

export const EXPORT_WHAT = 'danger.export.what' satisfies BillingKey;

export const EXPORT_FAILED = 'danger.export.failed' satisfies BillingKey;

/** The owner's close. */
export const CLOSE_PATH = '/admin/settings/close';

export const CLOSE_ACCOUNT = 'danger.close.button' satisfies BillingKey;

/** A choice by the window's days, :count their spelling. */
export const CLOSE_WHAT = 'danger.close.what' satisfies BillingKey;

export const CLOSE_TITLE = 'danger.close.title' satisfies BillingKey;

/** The dialog's first paragraph, a choice by the window's days, the day and the zone named. */
export const CLOSE_WHEN = 'danger.close.when' satisfies BillingKey;

export const CLOSE_KEEPS = 'danger.close.keeps' satisfies BillingKey;

export const CLOSE_PAUSES = 'danger.close.pauses' satisfies BillingKey;

export const CLOSE_BILLING = 'danger.close.billing' satisfies BillingKey;

export const CLOSE_FAILED = 'danger.close.failed' satisfies BillingKey;

/**
 * What the server says of the close: the day it would close on now, the way back, the closing
 * sentence while closing, and the retention window's days with their spelling in words.
 */
export type Close = { day: string; way_back: string; notice: string | null; window: { count: number; spelled: string } };

type Props = {
    close: Close;
    /** The tenant's zone, named beside the day. */
    zone: string;
    /**
     * Whether the close is offered: false in a support session, whose close the server refuses,
     * since an operator closes a tenant from the console alone, with a reason and an audit row.
     */
    closable: boolean;
};

export function DangerZone({ close, zone, closable }: Props) {
    const words = useBilling();
    const download = useDownload({ type: 'application/zip', fallback: 'export.zip', failure: words.text(EXPORT_FAILED) });
    const toasts = useToasts();
    const shell = useShell();
    // The window's days as the two sentences name them, spelled in words by the server.
    const days = { count: close.window.spelled };
    const [open, setOpen] = useState(false);
    const [closing, setClosing] = useState(false);

    const confirm = () => {
        let refused = false;
        const owned = owningFailure((failure) => {
            refused = true;
            toasts.push('error', failure.sentence ?? words.text(CLOSE_FAILED));
        }, shell);

        router.post(
            CLOSE_PATH,
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setClosing(true);
                    owned.onStart();
                },
                onSuccess: () => setOpen(false),
                onFinish: () => {
                    setClosing(false);
                    owned.onFinish();

                    if (refused) {
                        router.reload({ only: ['close'], ...owning(() => undefined) });
                    }
                },
            },
        );
    };

    return (
        <section className="admin-section admin-section--danger admin-settings-danger rounded-xl border border-destructive/50 p-4" aria-labelledby="section-danger">
            <h2 id="section-danger" className="mb-3 font-semibold">
                {words.text('danger.title')}
            </h2>
            <div className="flex flex-col gap-3">
                <p className="admin-settings-danger__what text-sm text-muted-foreground">{words.text(EXPORT_WHAT)}</p>
                <Button className="admin-settings-export self-start" busy={download.busy} busyLabel={words.text('danger.export.busy')} onClick={() => download.start(EXPORT_PATH)}>
                    {words.text(EXPORT_EVERYTHING)}
                </Button>
                {(closable || close.notice !== null) && (
                    <div className="admin-settings-close flex flex-col gap-3 border-t pt-3">
                        {close.notice === null ? (
                            <>
                                <p className="admin-settings-close__what text-sm text-muted-foreground">{words.choice(CLOSE_WHAT, close.window.count, days)}</p>
                                <Button destructive className="admin-settings-close__button self-start" onClick={() => setOpen(true)}>
                                    {words.text(CLOSE_ACCOUNT)}
                                </Button>
                            </>
                        ) : (
                            <p className="admin-settings-close__closing text-sm">{close.notice}</p>
                        )}
                    </div>
                )}
            </div>
            {closable && close.notice === null && (
                <Confirm
                    open={open}
                    title={words.text(CLOSE_TITLE)}
                    name="admin-close-prompt"
                    confirmLabel={words.text('danger.close.confirm')}
                    busyLabel={words.text('danger.close.busy')}
                    pending={words.text('danger.close.pending')}
                    busy={closing}
                    onConfirm={confirm}
                    onDismiss={() => setOpen(false)}
                >
                    <div className="flex flex-col gap-2">
                        <p className="admin-close-prompt__when">{words.choice(CLOSE_WHEN, close.window.count, { ...days, day: close.day, zone })}</p>
                        <p className="admin-close-prompt__keeps">{words.text(CLOSE_KEEPS)}</p>
                        <p className="admin-close-prompt__pauses">{words.text(CLOSE_PAUSES)}</p>
                        <p className="admin-close-prompt__billing">{words.text(CLOSE_BILLING)}</p>
                        <p className="admin-close-prompt__way-back">{close.way_back}</p>
                    </div>
                </Confirm>
            )}
        </section>
    );
}
