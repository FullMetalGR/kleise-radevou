import type { ReactNode } from 'react';
import { useShell } from '@/lib/strings';
import { Button } from './button';
import { Modal } from './modal';

/*
 * The confirm, on the modal: a body, Keep it, and the confirm, in that order, so focus lands on
 * the first control and it is never the confirm, the body's own field where it has one and Keep it
 * where it has none. Keep it, Escape and a backdrop press dismiss it, firing nothing. One confirm
 * serves every overlay that asks before it acts: the booking dialog's Reject and Cancel, and the
 * console's Suspend and Resume.
 *
 * Nothing is optimistic. While the confirm is in flight no path dismisses it, the modal's close
 * request answered with nothing, so the overlay closes only when the screen hands back the visit's
 * end; Keep it is disabled and says why in text beside it, as every disabled control does, and the
 * confirm shows its busy state. The confirm is destructive unless the overlay says otherwise:
 * undoing a suspension is no destructive act. Keep it is the kit's, from the strings the confirm
 * is given; every other word is the overlay's.
 */

type Props = {
    open: boolean;
    title: string;
    /** The overlay's class, and the stem of its parts' classes. */
    name: string;
    confirmLabel: string;
    busyLabel: string;
    /** Why Keep it is disabled while the confirm is in flight, in words. */
    pending: string;
    busy: boolean;
    /** Whether the confirm is the destructive button; it is unless told otherwise. */
    destructive?: boolean;
    onConfirm: () => void;
    onDismiss: () => void;
    children: ReactNode;
};

function nothing(): void {}

export function Confirm({ open, title, name, confirmLabel, busyLabel, pending, busy, destructive = true, onConfirm, onDismiss, children }: Props) {
    const shell = useShell();
    const pendingId = `${name}__pending`;

    return (
        <Modal
            open={open}
            title={title}
            onClose={busy ? nothing : onDismiss}
            className={name}
            footer={
                <>
                    {busy && (
                        <p id={pendingId} role="status" className={`${pendingId} mr-auto self-center text-sm text-muted-foreground`}>
                            {pending}
                        </p>
                    )}
                    <Button className={`${name}__keep`} disabled={busy} aria-describedby={busy ? pendingId : undefined} onClick={onDismiss}>
                        {shell.text('kit.confirm.keep')}
                    </Button>
                    <Button variant="primary" destructive={destructive} className={`${name}__confirm`} busy={busy} busyLabel={busyLabel} onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            {children}
        </Modal>
    );
}
