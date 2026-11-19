import { Card } from '@/components/ui/card';
import { RowSelectButton } from '@/components/ui/row-select-button';
import { StatusChip } from '@/components/ui/status-chip';
import { Table, TableBody, TableHead, TableRow, Td, Th } from '@/components/ui/table';
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
        <Card flush className="admin-card admin-services" aria-labelledby="services-head">
            <header className="flex items-center justify-between border-b px-4 py-3">
                <h2 id="services-head" className="font-semibold">
                    {words.text('table.title')}
                </h2>
                <p className="admin-services__count text-sm text-muted-foreground">{words.choice('table.count', rows.length)}</p>
            </header>
            <Table>
                <TableHead>
                    <TableRow>
                        {HEADS.map((head) => (
                            <Th key={head} className={head === 'table.price' ? 'text-right' : undefined}>
                                {words.text(head)}
                            </Th>
                        ))}
                    </TableRow>
                </TableHead>
                <TableBody>
                    {rows.map((service) => (
                        <TableRow key={service.id} className="admin-services__row cursor-pointer" onClick={() => onSelect(service.id)}>
                            <Td label={words.text('table.id')} className="admin-services__id">
                                {service.id}
                            </Td>
                            <Td label={words.text('table.name')} header>
                                <RowSelectButton name={service.name} selected={false} onSelect={() => onSelect(service.id)} />
                                <p className="admin-services__sub text-xs text-muted-foreground">{subLine(service, words)}</p>
                            </Td>
                            <Td label={words.text('table.category')} className="admin-services__category">
                                {service.category ?? ''}
                            </Td>
                            <Td label={words.text('table.duration')} className="admin-services__duration">
                                {words.text('table.minutes', { n: String(service.duration_min) })}
                            </Td>
                            <Td label={words.text('table.price')} className="admin-services__price text-right">
                                {formatAmount(service.price_minor, service.currency)}
                            </Td>
                            <Td label={words.text('table.payment')} className="admin-services__payment">
                                {paymentLabel(service.payment_mode, words)}
                            </Td>
                            <Td label={words.text('table.status')}>
                                <span className="flex items-center gap-2">
                                    <StatusChip status={service.status} label={statusWord(service.status, words)} />
                                    <span className={`admin-services__online text-xs ${service.online ? 'text-muted-foreground' : 'admin-services__online--off font-medium'}`}>{words.text(service.online ? ONLINE : NOT_ONLINE)}</span>
                                </span>
                            </Td>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </Card>
    );
}
