import { useRef, useState } from 'react';
import { type MoveChoice, MoveConfirm } from '@/bookings/MoveConfirm';
import { memberChanges, type MoveSegment, namedCrossings, readVerdict, useMoveWrite } from '@/bookings/moving';
import type { BookingsWords } from '@/bookings/words';
import type { TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import type { Crossing, Failure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import { type CalendarBooking, type Drop, type DropMove, moveOfDrop } from './model';

/*
 * A drop on the calendar. The grid has already put the block back; the drop is read as a move
 * (moveOfDrop), and a drop that changes nothing does nothing. Before anything is written the
 * move's read is asked for the verdict on the new start with the staff map: not placeable, or the
 * read refused, is the server's sentence as an error toast, and no answer at all the screen's
 * own. Placeable opens the Move form's own confirmation, from and to, the member change and the
 * crossings by name; Keep it writes nothing. Its confirm posts the write the form posts: an
 * unaccepted warning puts the write's crossings into the open confirmation for one more confirm;
 * any other refusal closes it and is toasted as the read's is. Success is the redirect back with
 * its flash, the window then re-read, so the block is drawn where the server says it is and
 * nothing is optimistic. One drop at a time.
 */

const NOT_MOVED = 'move.not_moved';

/** A drop the server called placeable, waiting on the confirmation. */
type Pending = { booking: CalendarBooking; move: DropMove; segments: MoveSegment[]; crossings: Crossing[] };

export type Dropping = {
    drop: (drop: Drop) => void;
    pending: Pending | null;
    busy: boolean;
    /** The crossings the open confirmation shows: the write's where it named some, else the verdict's. */
    shown: Crossing[];
    keep: () => void;
    confirm: (choice: MoveChoice) => void;
};

/** The sentence a refused write is toasted in: the server's, else the screen's own. */
function sentenceOf(failure: Failure, words: BookingsWords): string {
    return failure.code !== null && failure.sentence !== null ? failure.sentence : words.text(NOT_MOVED);
}

export function useDropping({ bookings, timezone, words }: { bookings: readonly CalendarBooking[]; timezone: string; words: BookingsWords }): Dropping {
    const shell = useShell();
    const toasts = useToasts();
    const write = useMoveWrite();
    const [pending, setPending] = useState<Pending | null>(null);
    // A drop is asked about while the last one's verdict is out; a second is let go.
    const asking = useRef(false);

    const drop = (dropped: Drop) => {
        const booking = bookings.find((each) => each.uuid === dropped.block.uuid);
        const move = booking === undefined ? null : moveOfDrop(booking, dropped, timezone);

        if (booking === undefined || move === null || asking.current || pending !== null) {
            return;
        }

        asking.current = true;
        void readVerdict(move.uuid, move.start, move.staff, shell).then((verdict) => {
            asking.current = false;

            if (verdict.kind === 'placeable') {
                write.reset();
                setPending({ booking, move, segments: verdict.segments, crossings: verdict.crossings });

                return;
            }

            toasts.push('error', verdict.kind === 'refused' ? verdict.sentence : words.text(NOT_MOVED));
        });
    };

    const confirm = (choice: MoveChoice) => {
        if (pending === null) {
            return;
        }

        write.post(pending.move, choice, {
            moved: () => setPending(null),
            refused: (failure) => {
                setPending(null);
                toasts.push('error', sentenceOf(failure, words));
            },
        });
    };

    return {
        drop,
        pending,
        busy: write.busy,
        shown: write.written ?? pending?.crossings ?? [],
        keep: () => setPending(null),
        confirm,
    };
}

/** The confirmation a placeable drop opens: the Move form's own. */
export function DropConfirm({ dropping, clock }: { dropping: Dropping; clock: TenantClock }) {
    const { pending } = dropping;

    if (pending === null) {
        return null;
    }

    return (
        <MoveConfirm
            open={true}
            booking={pending.booking}
            to={pending.move.start}
            changes={memberChanges(pending.move.staff, pending.segments)}
            crossings={namedCrossings(dropping.shown, pending.segments)}
            clock={clock}
            busy={dropping.busy}
            onConfirm={dropping.confirm}
            onDismiss={dropping.keep}
        />
    );
}
