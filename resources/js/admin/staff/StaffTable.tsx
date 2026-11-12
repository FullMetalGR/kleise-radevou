import { Link } from '@inertiajs/react';
import { StatusChip } from '@/components/ui/status-chip';
import { TableScroll } from '@/components/ui/table-scroll';
import type { TenantClock } from '@/lib/format';
import { memberHue } from '@/lib/hue';
import { inPage } from '@/lib/in-page';
import { placed } from '@/lib/strings';
import { hoursFigure, hoursLine, type StaffMember, statusWord } from './model';
import { useStaff } from './words';

/*
 * The staff table: ID, Name, Title, Email, Status and Hours this week. The name follows a dot in
 * the member's calendar colour, their own or the spread's (lib/hue.ts), and is the link to their
 * page. Hours this week is one whole line per case (every week or this week, with the week's
 * closures reaching the member or without), its :hours the figure set as the link to Shifts where
 * the hours are set, placed where the line puts it; all of it the server's reading
 * (ShiftRecords::hoursOf) and only spelled here. The implicit member is an ordinary row here. The
 * row keeps a click handler as a larger mouse target. Every word is a key of the staff section,
 * the count a choice key.
 */

/** The Shifts screen, where a member's hours are set. */
const SHIFTS = '/admin/shifts';

type Props = {
    rows: readonly StaffMember[];
    clock: TenantClock;
    onSelect: (id: number) => void;
};

export function StaffTable({ rows, clock, onSelect }: Props) {
    const words = useStaff();

    return (
        <section className="admin-card admin-staff rounded-xl border" aria-labelledby="staff-head">
            <header className="flex items-center justify-between gap-3 border-b px-4 py-3">
                <h2 id="staff-head" className="font-semibold">
                    {words.text('table.title')}
                </h2>
                <p className="admin-staff__count text-sm text-muted-foreground">{words.choice('table.count', rows.length)}</p>
            </header>
            <TableScroll>
                <table className="admin-table w-full text-sm">
                    <thead>
                        <tr className="text-left">
                            <th className="px-4 py-2">{words.text('table.id')}</th>
                            <th className="px-4 py-2">{words.text('table.name')}</th>
                            <th className="px-4 py-2">{words.text('table.job_title')}</th>
                            <th className="px-4 py-2">{words.text('table.email')}</th>
                            <th className="px-4 py-2">{words.text('table.status')}</th>
                            <th className="px-4 py-2">{words.text('table.hours')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((member) => (
                            <tr key={member.id} className="admin-staff__row cursor-pointer border-t" onClick={() => onSelect(member.id)}>
                                <td className="admin-staff__id px-4 py-2 font-mono">{member.id}</td>
                                <td className="px-4 py-2 whitespace-nowrap">
                                    <span className="admin-staff__dot mr-2 inline-block size-4 rounded-full align-[-3px]" style={{ backgroundColor: memberHue(member) }} aria-hidden="true" />
                                    <a
                                        href={`/admin/staff/${member.id}`}
                                        className="admin-staff__name font-medium underline underline-offset-3"
                                        onClick={(event) => inPage(event, () => onSelect(member.id))}
                                    >
                                        {member.name}
                                    </a>
                                </td>
                                <td className="admin-staff__title px-4 py-2">{member.title ?? ''}</td>
                                <td className="admin-staff__email px-4 py-2">{member.email ?? ''}</td>
                                <td className="px-4 py-2">
                                    <StatusChip status={member.status} label={statusWord(member.status, words)} />
                                </td>
                                <td className="admin-staff__hours px-4 py-2 text-muted-foreground">
                                    {placed(hoursLine(member.hours, clock, words), {
                                        hours: (
                                            <Link href={SHIFTS} className="admin-staff__figure font-mono text-foreground underline underline-offset-3" onClick={(event) => event.stopPropagation()}>
                                                {hoursFigure(member.hours, words)}
                                            </Link>
                                        ),
                                    })}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableScroll>
        </section>
    );
}
