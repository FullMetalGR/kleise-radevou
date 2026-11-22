import { Link } from '@inertiajs/react';
import { Initials } from '@/components/initials';
import { Card } from '@/components/ui/card';
import { StatusChip } from '@/components/ui/status-chip';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
import type { TenantClock } from '@/lib/format';
import { memberHue } from '@/lib/hue';
import { inPage } from '@/lib/in-page';
import { placed } from '@/lib/strings';
import { hoursFigure, hoursLine, type StaffMember, statusWord } from './model';
import { useStaff } from './words';

/*
 * The staff table: ID, Name, Title, Email, Status and Hours this week. The name follows the
 * member's initials ringed in their calendar colour, their own or the spread's (lib/hue.ts), and is
 * the link to their page. Hours this week is one whole line per case (every week or this week,
 * with the week's closures reaching the member or without), its :hours the figure set as the link
 * to Shifts where the hours are set, placed where the line puts it; all of it the server's reading
 * (ShiftRecords::hoursOf) and only spelled here. The implicit member is an ordinary row here. The
 * row keeps a click handler as a larger mouse target. Every word is a key of the staff section,
 * the count a choice key.
 */

/** The Shifts screen, where a member's hours are set. */
const SHIFTS = '/admin/shifts';

/*
 * The room around the heading: the first cell's sides on a wide card, the rows' sides where the
 * table reads as rows, below the wide breakpoint or on a card under 42rem (read off the screen's
 * root, a query container as wide as the card), so heading and rows share a start line.
 */
const HEAD_ROOM = 'px-6.5 pt-5 pb-3.5 max-wide:px-4.5 @max-2xl:px-4.5';

type Props = {
    rows: readonly StaffMember[];
    clock: TenantClock;
    onSelect: (id: number) => void;
};

export function StaffTable({ rows, clock, onSelect }: Props) {
    const words = useStaff();

    return (
        <Card flush className="admin-card admin-staff" aria-labelledby="staff-head">
            <header className={`flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 ${HEAD_ROOM}`}>
                <h2 id="staff-head" className="text-section-title">
                    {words.text('table.title')}
                </h2>
                <p className="admin-staff__count text-body-sm text-muted-foreground">{words.choice('table.count', rows.length)}</p>
            </header>
            <Table>
                <TableHead>
                    <TableRow>
                        <Th>{words.text('table.id')}</Th>
                        <Th>{words.text('table.name')}</Th>
                        <Th>{words.text('table.job_title')}</Th>
                        <Th>{words.text('table.email')}</Th>
                        <Th>{words.text('table.status')}</Th>
                        <Th>{words.text('table.hours')}</Th>
                    </TableRow>
                </TableHead>
                <TableBody>
                    {rows.map((member) => (
                        <TableRow key={member.id} className="admin-staff__row cursor-pointer" onClick={() => onSelect(member.id)}>
                            <Td label={words.text('table.id')} className="admin-staff__id">
                                {member.id}
                            </Td>
                            <Td label={words.text('table.name')} header>
                                <span className="flex items-center gap-2.5">
                                    <Initials name={member.name} hue={memberHue(member)} size="list" className="admin-staff__dot" />
                                    <a
                                        href={`/admin/staff/${member.id}`}
                                        className="admin-staff__name font-medium underline underline-offset-3"
                                        onClick={(event) => inPage(event, () => onSelect(member.id))}
                                    >
                                        {member.name}
                                    </a>
                                </span>
                            </Td>
                            <Td label={words.text('table.job_title')} className="admin-staff__title">
                                {member.title ?? ''}
                            </Td>
                            <Td label={words.text('table.email')} className="admin-staff__email">
                                {member.email ?? ''}
                            </Td>
                            <Td label={words.text('table.status')}>
                                <StatusChip status={member.status} label={statusWord(member.status, words)} />
                            </Td>
                            <Td label={words.text('table.hours')} className="admin-staff__hours text-muted-foreground">
                                {placed(hoursLine(member.hours, clock, words), {
                                    hours: (
                                        <Link href={SHIFTS} className="admin-staff__figure text-foreground underline underline-offset-3" onClick={(event) => event.stopPropagation()}>
                                            {hoursFigure(member.hours, words)}
                                        </Link>
                                    ),
                                })}
                            </Td>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </Card>
    );
}
