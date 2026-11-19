import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import { Strip } from '@/figures/kit';
import { formatOccupancy, hoursOf, type Report } from './model';
import { ReportCard } from './ReportCard';
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
        <ReportCard name="occupancy" labelledBy="reports-occupancy-head">
            <Strip titleId="reports-occupancy-head" title={words.text('occupancy.title')} />
            <Table>
                <TableHead>
                    <TableRow>
                        <Th>{words.text('tables.staff')}</Th>
                        <Th className="text-right">{words.text('occupancy.booked')}</Th>
                        <Th className="text-right">{words.text('occupancy.available')}</Th>
                        <Th className="text-right">{words.text('tiles.occupancy')}</Th>
                    </TableRow>
                </TableHead>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={row.id} className="admin-reports-occupancy__row">
                            <Td label={words.text('tables.staff')} header>
                                {row.name}
                            </Td>
                            <Td label={words.text('occupancy.booked')} className="text-right">
                                {hoursOf(row.booked_minutes, words)}
                            </Td>
                            <Td label={words.text('occupancy.available')} className="text-right">
                                {hoursOf(row.available_minutes, words)}
                            </Td>
                            <Td label={words.text('tiles.occupancy')} className="text-right">
                                {row.occupancy === null ? words.text('occupancy.no_hours') : formatOccupancy(row.occupancy, words.locale)}
                            </Td>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </ReportCard>
    );
}
