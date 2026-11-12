import { useState } from 'react';
import { CheckField } from '@/components/ui/check-field';
import { Confirm } from '@/components/ui/confirm';
import { Notice } from '@/components/ui/notice';
import { formatInstant, type TenantClock } from '@/lib/format';
import { type Booking, startOf } from './model';
import type { MemberChange, NamedCrossing } from './moving';
import { type BookingsWords, useBookings } from './words';

/*
 * The confirmation every move by the business passes through: the Move form's Save opens it, and
 * a drop on the calendar is to open the same one. It takes what it shows and depends on neither:
 * the booking, the start it goes to, each member change and each crossing, already named. It says
 * what moves from where to where in the tenant's time, each service given to another member, and
 * where the start crosses something, a warning naming per service whether it is outside that
 * member's working hours or on a closure. A "Tell the customer" tick box, ticked each time it
 * opens, decides whether the customer is sent the move; where the customer was erased there is
 * nobody to tell, and the box is left out.
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

/**
 * A crossing's line: the service with its member, or the whole line naming neither where the move
 * cannot read one.
 */
function crossingLine(crossing: NamedCrossing, words: BookingsWords): string {
    const closure = crossing.kind === 'closure';

    if (crossing.service === null || crossing.staff === null) {
        return words.text(closure ? 'move.confirm.closure_unnamed' : 'move.confirm.hours_unnamed');
    }

    return words.text(closure ? 'move.confirm.closure' : 'move.confirm.hours', { service: crossing.service, staff: crossing.staff });
}

export function MoveConfirm({ open, booking, to, changes, crossings, clock, busy, onConfirm, onDismiss }: Props) {
    const words = useBookings();
    const [tell, setTell] = useState(true);
    const [wasOpen, setWasOpen] = useState(open);
    const tellable = booking.customer_name !== null;

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setTell(true);
        }
    }

    return (
        <Confirm
            open={open}
            title={words.text('move.confirm.title')}
            name="admin-move-confirm"
            confirmLabel={words.text('move.confirm.move')}
            busyLabel={words.text('move.confirm.busy')}
            pending={words.text('move.confirm.pending')}
            busy={busy}
            destructive={false}
            onConfirm={() => onConfirm({ warningAccepted: crossings.length > 0, tellCustomer: tellable && tell })}
            onDismiss={onDismiss}
        >
            <div className="flex flex-col gap-3 text-sm">
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
                {crossings.length > 0 && (
                    <Notice kind="warning" className="admin-move-confirm__warning">
                        <p>{words.text('move.confirm.warning')}</p>
                        <ul className="mt-1 flex list-disc flex-col gap-1 pl-5">
                            {crossings.map((crossing, index) => (
                                <li key={index} className="admin-move-confirm__crossing">
                                    {crossingLine(crossing, words)}
                                </li>
                            ))}
                        </ul>
                    </Notice>
                )}
                {tellable && <CheckField id="move-tell" label={words.text('move.confirm.tell')} help={words.text('move.confirm.tell_help')} checked={tell} onChange={setTell} />}
            </div>
        </Confirm>
    );
}
