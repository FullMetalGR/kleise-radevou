import { useState } from 'react';
import { CheckField } from '@/components/ui/check-field';
import { Confirm } from '@/components/ui/confirm';
import { Notice } from '@/components/ui/notice';
import { formatInstant, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import { reasonLines } from './closed-time';
import { type Booking, customerName, distinct, startOf } from './model';
import type { MemberChange, NamedCrossing } from './moving';
import { useBookings } from './words';

/*
 * The confirmation every move by the business passes through: the Move form's Save opens it, and
 * a drop on the calendar is to open the same one. It takes what it shows and depends on neither:
 * the booking, the start it goes to, each member change and each crossing, already named. It says
 * what moves from where to where in the tenant's time and each service given to another member. A
 * "Tell the customer" tick box, ticked each time it opens, decides whether the customer is sent
 * the move; where the customer was erased there is nobody to tell, and the box is left out.
 *
 * Where the start crosses closed time it is a warning before anything is saved: its own title,
 * the booking named by its customer and services, each reason in a plain sentence (closed-time),
 * the question whether to go ahead, and Move anyway beside Cancel. A start on open time reads
 * Move this booking, Move booking and the kit's Keep it, as it always has.
 *
 * The confirm hands back what the person chose: the warning accepted exactly when it was shown,
 * and the tick. It is the kit's Confirm, a modal over the dialog as Cancel's is, not destructive,
 * and while the move is in flight it cannot be dismissed.
 */

export type MoveChoice = { warningAccepted: boolean; tellCustomer: boolean };

type Props = {
    open: boolean;
    booking: Booking;
    /** The start the booking goes to, an RFC 3339 instant. */
    to: string;
    changes: readonly MemberChange[];
    crossings: readonly NamedCrossing[];
    clock: TenantClock;
    busy: boolean;
    onConfirm: (choice: MoveChoice) => void;
    onDismiss: () => void;
};

export function MoveConfirm({ open, booking, to, changes, crossings, clock, busy, onConfirm, onDismiss }: Props) {
    const words = useBookings();
    const [tell, setTell] = useState(true);
    const [wasOpen, setWasOpen] = useState(open);
    const tellable = booking.customer_name !== null;
    const closed = crossings.length > 0;

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setTell(true);
        }
    }

    return (
        <Confirm
            open={open}
            title={words.text(closed ? 'move.confirm.closed_title' : 'move.confirm.title')}
            name="admin-move-confirm"
            confirmLabel={words.text(closed ? 'move.confirm.move_anyway' : 'move.confirm.move')}
            keepLabel={closed ? words.text('move.confirm.cancel') : undefined}
            busyLabel={words.text('move.confirm.busy')}
            pending={words.text('move.confirm.pending')}
            busy={busy}
            destructive={false}
            onConfirm={() => onConfirm({ warningAccepted: closed, tellCustomer: tellable && tell })}
            onDismiss={onDismiss}
        >
            <div className="flex flex-col gap-3 text-sm">
                {closed && (
                    <p className="admin-move-confirm__booking">
                        {words.text('move.confirm.booking', { customer: customerName(booking, words), services: listOf(distinct(booking.items.map((item) => item.service_name)), words.language) })}
                    </p>
                )}
                <p className="admin-move-confirm__from-to">{words.text('move.confirm.from_to', { from: formatInstant(startOf(booking), clock), to: formatInstant(to, clock) })}</p>
                {changes.length > 0 && (
                    <ul className="flex flex-col gap-1">
                        {changes.map((change) => (
                            <li key={change.segment} className="admin-move-confirm__change">
                                {change.to === null
                                    ? words.text('move.confirm.member_unnamed', { service: change.service, from: change.from })
                                    : words.text('move.confirm.member', { service: change.service, from: change.from, to: change.to })}
                            </li>
                        ))}
                    </ul>
                )}
                {closed && (
                    <Notice kind="warning" className="admin-move-confirm__warning">
                        <ul className="flex list-disc flex-col gap-1 pl-5">
                            {reasonLines(crossings, words, clock).map((line) => (
                                <li key={line} className="admin-move-confirm__crossing">
                                    {line}
                                </li>
                            ))}
                        </ul>
                    </Notice>
                )}
                {closed && <p className="admin-move-confirm__sure">{words.text('move.confirm.sure')}</p>}
                {tellable && <CheckField id="move-tell" label={words.text('move.confirm.tell')} help={words.text('move.confirm.tell_help')} checked={tell} onChange={setTell} />}
            </div>
        </Confirm>
    );
}
