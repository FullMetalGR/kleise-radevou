import { RowSelectButton } from '@/components/ui/row-select-button';
import { StatusChip } from '@/components/ui/status-chip';
import { TableScroll } from '@/components/ui/table-scroll';
import { formatAmount } from '@/lib/format';
import { paymentLabel, type Service, statusWord, subLine } from './model';
import { type ServicesKey, useServices } from './words';

/*
 * The catalog table: ID, Name, Category, Duration, Price, Payment, Status. The ID is a real
 * column because the embed's preselect links take numeric ids and this is the only place an owner
 * reads one. The category reads blank for a row under none, and the status cell marks the row
 * Online or Not online beside its chip, since a row kept off the booking page is still active and
 * bookable from the wizard. A grey sub-line under the name carries the duration and the
 * processing gap when non-zero. The price is spelled in the row's own currency and scale.
 * Selection is the only row action; the row keeps a click handler as a larger mouse target. Every
 * word is a key of the services section the screen provides.
 */

export const ONLINE = 'table.online' satisfies ServicesKey;

export const NOT_ONLINE = 'table.not_online' satisfies ServicesKey;

/** The seven column heads, in order. */
const HEADS = ['table.id', 'table.name', 'table.category', 'table.duration', 'table.price', 'table.payment', 'table.status'] as const satisfies readonly ServicesKey[];

type Props = {
    rows: readonly Service[];
    onSelect: (id: number) => void;
};

export function ServicesTable({ rows, onSelect }: Props) {
    const words = useServices();

    return (
        <section className="admin-card admin-services rounded-xl border" aria-labelledby="services-head">
            <header className="flex items-center justify-between border-b px-4 py-3">
                <h2 id="services-head" className="font-semibold">
                    {words.text('table.title')}
                </h2>
                <p className="admin-services__count text-sm text-muted-foreground">{words.choice('table.count', rows.length)}</p>
            </header>
            <TableScroll>
                <table className="admin-table w-full text-sm">
                    <thead>
                        <tr className="text-left">
                            {HEADS.map((head) => (
                                <th key={head} className={`px-4 py-2${head === 'table.price' ? ' text-right' : ''}`}>
                                    {words.text(head)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((service) => (
                            <tr key={service.id} className="admin-services__row cursor-pointer border-t" onClick={() => onSelect(service.id)}>
                                <td className="admin-services__id px-4 py-2 font-mono">{service.id}</td>
                                <td className="px-4 py-2">
                                    <RowSelectButton name={service.name} selected={false} onSelect={() => onSelect(service.id)} />
                                    <p className="admin-services__sub text-xs text-muted-foreground">{subLine(service, words)}</p>
                                </td>
                                <td className="admin-services__category px-4 py-2">{service.category ?? ''}</td>
                                <td className="admin-services__duration px-4 py-2 font-mono">{words.text('table.minutes', { n: String(service.duration_min) })}</td>
                                <td className="admin-services__price px-4 py-2 text-right font-mono">{formatAmount(service.price_minor, service.currency)}</td>
                                <td className="admin-services__payment px-4 py-2">{paymentLabel(service.payment_mode, words)}</td>
                                <td className="px-4 py-2">
                                    <span className="flex items-center gap-2">
                                        <StatusChip status={service.status} label={statusWord(service.status, words)} />
                                        <span className={`admin-services__online text-xs ${service.online ? 'text-muted-foreground' : 'admin-services__online--off font-medium'}`}>{words.text(service.online ? ONLINE : NOT_ONLINE)}</span>
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableScroll>
        </section>
    );
}
