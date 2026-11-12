import type { ReactNode } from 'react';

/*
 * Reports' cards below the tiles: the kit's card, a 14px radius on the border colour over the
 * card's ground. A status card is padded and holds its title inside (padded), its rows in
 * 14px type; Over time, Occupancy by staff and the By tables open on the figures kit's header
 * strip, and their tables run to the card's edges.
 */

export function Card({ name, labelledBy, padded = false, children }: { name: string; labelledBy: string; padded?: boolean; children: ReactNode }) {
    return (
        <section className={`admin-reports-${name} rounded-xl border bg-card ${padded ? 'flex flex-col gap-2 p-4 text-sm' : 'overflow-hidden'}`} aria-labelledby={labelledBy}>
            {children}
        </section>
    );
}
