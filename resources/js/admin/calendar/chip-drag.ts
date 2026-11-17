import { type DragEvent, useRef, useState } from 'react';
import type { Chip, ChipDrop } from './model';

/*
 * A chip dragged from one cell to another, by the browser's own drag and drop: a chip in a cell
 * drags where a drop is handled and the server says the booking is movable; a cell takes it unless
 * it is the cell the chip came from, and while the chip is held over a cell that cell shows it
 * would take it. The drop hands on the chip, the day of the cell and its row's member where the
 * row changed. A chip in a popover never drags, and nothing drags by keyboard.
 */

/** How a cell shows it would take the chip held over it. */
export const TARGETED = 'admin-calendar-target bg-primary/10 ring-2 ring-primary ring-inset';

/** A cell as a drag reads it: its key, its day, and its row's member, none in Month and in the other bookings' row. */
export type Spot = { key: string; date: string; staffId: number | null };

type Held = { chip: Chip; from: Spot };

type Source = { draggable: true; onDragStart: (event: DragEvent<HTMLElement>) => void; onDragEnd: () => void };

type Target = { onDragOver: (event: DragEvent<HTMLElement>) => void; onDragLeave: (event: DragEvent<HTMLElement>) => void; onDrop: (event: DragEvent<HTMLElement>) => void };

export type ChipDrag = {
    /** What a chip in a cell carries: a drag where it may move, else nothing. */
    source: (chip: Chip, from: Spot) => Source | undefined;
    /** What a cell carries to take a drop. */
    target: (spot: Spot) => Target;
    /** The key of the cell a chip is held over. */
    over: string | null;
};

export function useChipDrag(onDrop: ((drop: ChipDrop) => void) | undefined): ChipDrag {
    const held = useRef<Held | null>(null);
    const [over, setOver] = useState<string | null>(null);

    const release = () => {
        held.current = null;
        setOver(null);
    };

    const source = (chip: Chip, from: Spot): Source | undefined =>
        onDrop === undefined || !chip.movable
            ? undefined
            : {
                  draggable: true,
                  onDragStart: (event) => {
                      held.current = { chip, from };
                      event.dataTransfer.effectAllowed = 'move';
                      event.dataTransfer.setData('text/plain', chip.uuid);
                  },
                  onDragEnd: release,
              };

    const takes = (spot: Spot) => held.current !== null && held.current.from.key !== spot.key;

    const target = (spot: Spot): Target => ({
        onDragOver: (event) => {
            if (takes(spot)) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                setOver(spot.key);
            }
        },
        onDragLeave: (event) => {
            if (!(event.relatedTarget instanceof Node && event.currentTarget.contains(event.relatedTarget))) {
                setOver((current) => (current === spot.key ? null : current));
            }
        },
        onDrop: (event) => {
            const dragged = held.current;

            if (dragged === null || !takes(spot)) {
                return;
            }

            event.preventDefault();
            release();
            onDrop?.({ chip: dragged.chip, date: spot.date, staffId: spot.staffId !== dragged.from.staffId ? spot.staffId : null });
        },
    });

    return { source, target, over };
}
