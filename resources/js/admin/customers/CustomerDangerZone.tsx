import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Confirm } from '@/components/ui/confirm';
import { Modal } from '@/components/ui/modal';
import { useShell } from '@/lib/strings';
import { owning, owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import { type CustomersKey, useCustomers } from './words';

/*
 * A customer's Danger zone, on the pattern of the staff editor's (3.4): one sentence saying what
 * an erasure takes and what it keeps, and Erase this customer as a danger button, rendered by
 * the page only for a viewer holding manageBookings.
 *
 * The press opens a dialog, and which one is the server's answer, read off the page's erasure
 * prop before any press: while a booking of the address is live, the refusal sentence the POST
 * would answer, naming their count and saying to cancel them first, with nothing to confirm;
 * otherwise the kit's confirm, naming how many bookings lose the person. Nothing is optimistic:
 * the confirm is busy until the server answers, a landed erasure leaves for the Customers list
 * on the server's redirect with its flash, and a refusal, a 409 from a booking that went live
 * since the page was read among them, is its sentence in an error toast with the dialog left as
 * it stood, the page then reading the erasure prop again so the dialog shows what the server now
 * says. Every word is a key of the customers section, the confirm's body one choice by the
 * count; the refusal comes worded from the server.
 */

/** What the server says of an erasure now: the refusal sentence, or null where none is live. */
export type Erasure = { refusal: string | null };

export const ERASE_WHAT = 'erasure.what' satisfies CustomersKey;

export const ERASE_ERROR = 'erasure.error' satisfies CustomersKey;

export const REFUSED_TITLE = 'erasure.refused_title' satisfies CustomersKey;

/** The confirm's body, a choice by how many bookings lose the person. */
export const ERASURE_COUNT = 'erasure.count' satisfies CustomersKey;

type Props = {
    /** The customer's page; the erasure posts beneath it. */
    url: string;
    /** How many bookings the address holds, every one of which loses the person. */
    bookings: number;
    erasure: Erasure;
};

export function CustomerDangerZone({ url, bookings, erasure }: Props) {
    const toasts = useToasts();
    const shell = useShell();
    const words = useCustomers();
    const [open, setOpen] = useState(false);
    const [erasing, setErasing] = useState(false);

    const erase = () => {
        let refused = false;
        const owned = owningFailure((failure) => {
            refused = true;
            toasts.push('error', failure.sentence ?? words.text(ERASE_ERROR));
        }, shell);

        router.post(
            `${url}/erase`,
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setErasing(true);
                    owned.onStart();
                },
                onFinish: () => {
                    setErasing(false);
                    owned.onFinish();

                    if (refused) {
                        router.reload({ only: ['erasure'], ...owning(() => undefined) });
                    }
                },
            },
        );
    };

    const close = () => setOpen(false);

    return (
        <>
            <section className="admin-section admin-section--danger admin-customer-danger rounded-xl border border-destructive/50 p-4" aria-labelledby="customer-danger">
                <h2 id="customer-danger" className="mb-3 font-semibold">
                    {words.text('erasure.title')}
                </h2>
                <div className="flex flex-col gap-3">
                    <p className="admin-customer-danger__what text-sm text-muted-foreground">{words.text(ERASE_WHAT)}</p>
                    <Button destructive className="admin-customer-erase self-start" onClick={() => setOpen(true)}>
                        {words.text('erasure.erase')}
                    </Button>
                </div>
            </section>
            {erasure.refusal === null ? (
                <Confirm
                    open={open}
                    title={words.text('erasure.confirm_title')}
                    name="admin-erase-prompt"
                    confirmLabel={words.text('erasure.confirm')}
                    busyLabel={words.text('erasure.busy')}
                    pending={words.text('erasure.pending')}
                    busy={erasing}
                    onConfirm={erase}
                    onDismiss={close}
                >
                    <p className="admin-erase-prompt__count">{words.choice(ERASURE_COUNT, bookings)}</p>
                </Confirm>
            ) : (
                <Modal
                    open={open}
                    title={words.text(REFUSED_TITLE)}
                    onClose={close}
                    className="admin-erase-refused"
                    footer={
                        <Button className="admin-erase-refused__close" onClick={close}>
                            {words.text('erasure.close')}
                        </Button>
                    }
                >
                    <p className="admin-erase-refused__sentence">{erasure.refusal}</p>
                </Modal>
            )}
        </>
    );
}
