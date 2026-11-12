import { TableScroll } from '@/components/ui/table-scroll';
import { Strip } from '@/figures/kit';
import { Card } from './Card';
import { formatOccupancy, hoursOf, type Report } from './model';
import type { ReportsWords } from './words';

/*
 * Occupancy by staff, between the status row and the By tables: a card with its header strip over
 * the table Staff, Booked, Available and Occupancy, a row a member in the order the server sends.
 * The hours are written as the Occupancy tile writes its own, the percentage to one decimal; a
 * member with nothing available reads "No hours". Available is the member's hours less closures
 * plus the booked minutes outside them, so no row passes 100 percent.
 */

export function OccupancyByStaff({ rows, words }: { rows: Report['occupancy']; words: ReportsWords }) {
    return (
        <Card name="occupancy" labelledBy="reports-occupancy-head">
            <Strip titleId="reports-occupancy-head" title={words.text('occupancy.title')} />
            <TableScroll>
                <table className="admin-table w-full text-sm">
                    <thead>
                        <tr className="text-left">
                            <th className="px-4 py-2">{words.text('tables.staff')}</th>
                            <th className="px-4 py-2 text-right">{words.text('occupancy.booked')}</th>
                            <th className="px-4 py-2 text-right">{words.text('occupancy.available')}</th>
                            <th className="px-4 py-2 text-right">{words.text('tiles.occupancy')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id} className="admin-reports-occupancy__row border-t">
                                <td className="px-4 py-2">{row.name}</td>
                                <td className="px-4 py-2 text-right font-mono">{hoursOf(row.booked_minutes, words)}</td>
                                <td className="px-4 py-2 text-right font-mono">{hoursOf(row.available_minutes, words)}</td>
                                <td className="px-4 py-2 text-right font-mono">{row.occupancy === null ? words.text('occupancy.no_hours') : formatOccupancy(row.occupancy, words.locale)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableScroll>
        </Card>
    );
}
