import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';

/*
 * Reports' cards below the tiles, each the admin's tile. A status card is padded and holds its
 * title inside (padded), its rows in 14px type; Over time, Occupancy by staff and the By tables
 * open on the figures kit's header strip, and their tables run to the card's edges, flush and
 * clipped to its corners.
 */

export function ReportCard({ name, labelledBy, padded = false, children }: { name: string; labelledBy: string; padded?: boolean; children: ReactNode }) {
    return (
        <Card flush={!padded} className={`admin-reports-${name} ${padded ? 'flex flex-col gap-2 text-sm' : 'overflow-hidden'}`} aria-labelledby={labelledBy}>
            {children}
        </Card>
    );
}
