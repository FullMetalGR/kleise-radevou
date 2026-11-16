import { router } from '@inertiajs/react';
import { useState } from 'react';
import { BookingsProvider, type BookingsWords } from '@/bookings/words';
import type { TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { type Failure, owningFailure } from '@/lib/visits';
import { BlackoutsCard, closureBody, type ClosureWho, type Draft, type InFlight } from './BlackoutsCard';
import { BookingsToMove, InsideChoices, InsideList, useClosureAdd } from './BookingsInside';
import type { Blackout } from './model';

type Line = Blackout & { staff_id?: number | null };

type Props = {
    /** The route a line or a draft is written to, by its member, null for the whole business. */
    pathOf: (staffId: number | null) => string;
    blackouts: readonly Line[];
    who: ClosureWho | null;
    clock: TenantClock;
    weekStart: number;
    granularity: number;
    title: string;
    idPrefix: string;
    onFailure: (failure: Failure) => void;
    /** Whether the screen shows members, so a list of bookings names each one's. */
    staffShown: boolean;
    /** The bookings section, which the list of bookings and the Move form read. */
    bookings: BookingsWords;
};

/**
 * One closures card and its two writes, each to its scope's route, the business's or a member's,
 * by the line or by Who: an add is a POST and a removal a DELETE carrying the dates and the
 * window seen, a folded run's first and last, never a row id. The card is told which action is
 * in flight, and for which row, so the pressed control alone shows busy. A landed add rekeys the
 * card so its draft clears; a refused one keeps it, with the sentence toasted.
 *
 * An add over bookings shows their list in the card in place of its form, with the answers the
 * server offers and Back (BookingsInside), and after Add and move them the list of bookings to
 * move opens over the page. The list's buttons wait for a removal in flight as every control of
 * the card does, and Delete waits for an answer: one write at a time, so none cuts another short.
 */
export function Closures({ pathOf, blackouts, who, clock, weekStart, granularity, title, idPrefix, onFailure, staffShown, bookings }: Props) {
    const shell = useShell();
    const [removing, setRemoving] = useState<InFlight>(null);
    const [added, setAdded] = useState(0);
    const [reasonTyped, setReasonTyped] = useState(false);
    const adding = useClosureAdd(onFailure);
    const inFlight: InFlight = adding.pressed !== null ? { action: 'add' } : removing;

    const add = (draft: Draft) => {
        setReasonTyped(draft.reason !== null);
        adding.add(pathOf(draft.staff_id), closureBody(draft), () => setAdded((count) => count + 1));
    };

    const remove = (blackout: Line) => {
        const owned = owningFailure(onFailure, shell);

        router.delete(pathOf(blackout.staff_id ?? null), {
            data: closureBody(blackout),
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                setRemoving({ action: 'remove', blackout });
                owned.onStart();
            },
            onFinish: () => {
                setRemoving(null);
                owned.onFinish();
            },
        });
    };

    const inside =
        adding.asked === null ? null : (
            <>
                <InsideList asked={adding.asked} staffShown={staffShown} clock={clock} reasonTyped={reasonTyped} />
                <InsideChoices adding={adding} held={removing !== null} />
            </>
        );

    return (
        <BookingsProvider words={bookings}>
            <BlackoutsCard
                key={added}
                title={title}
                idPrefix={idPrefix}
                blackouts={blackouts}
                who={who}
                clock={clock}
                weekStart={weekStart}
                granularity={granularity}
                inFlight={inFlight}
                onAdd={add}
                onRemove={remove}
                inside={inside}
            />
            {adding.toMove !== null && <BookingsToMove rows={adding.toMove} staffShown={staffShown} clock={clock} weekStart={weekStart} onDone={adding.done} />}
        </BookingsProvider>
    );
}
