import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Confirm } from '@/components/ui/confirm';
import { Field } from '@/components/ui/field';
import { Textarea } from '@/components/ui/input';
import { formatInstant, type TenantClock } from '@/lib/format';
import { useOneWrite } from '@/lib/one-write';
import type { Block } from './model';
import { type CustomersKey, useCustomers } from './words';

/*
 * A customer's Online booking card. Where the address is not blocked: the sentence that it can
 * book online, Reason, a textarea for the staff with its help line saying the customer sees only
 * that booking online is not available, and Block online booking, a danger button that asks
 * through the kit's confirm before it puts the reason as typed, or null for none, to the
 * customer's block route, PUT {page}/block. Where it is blocked: since when, in the tenant's zone
 * and format, the reason beneath or the line saying none was recorded, and Unblock, which deletes
 * the block, DELETE {page}/block. The hold then refuses the address, and Book now still books it.
 * Nothing is optimistic: both states read the page's block prop, which the redirect re-reads.
 * Every word is a key of the customers section.
 */

const BLOCKING = 'block.blocking' satisfies CustomersKey;

const UNBLOCKING = 'block.unblocking' satisfies CustomersKey;

type Props = { url: string; block: Block; clock: TenantClock };

export function CustomerBlockCard({ url, block, clock }: Props) {
    const words = useCustomers();
    const write = useOneWrite({ block: words.text(BLOCKING), unblock: words.text(UNBLOCKING) });
    const [reason, setReason] = useState('');
    const [asking, setAsking] = useState(false);
    const failed = (failure: { sentence: string | null }) => failure.sentence ?? words.text('block.error');

    const blockIt = () =>
        write.visit(
            'block',
            failed,
            () => setAsking(false),
            (options) => router.put(`${url}/block`, { reason: reason.trim() === '' ? null : reason }, options),
        );

    const unblock = () => write.visit('unblock', failed, () => undefined, (options) => router.delete(`${url}/block`, options));

    return (
        <section className="admin-card admin-customer-block flex flex-col gap-3 rounded-xl border p-4 text-sm" aria-labelledby="customer-block-head">
            <h2 id="customer-block-head">
                {words.text('block.title')}
            </h2>
            {block.blocked_at === null ? (
                <>
                    <p className="admin-customer-block__state">{words.text('block.open')}</p>
                    <Field id="customer-block-reason" label={words.text('block.reason')} help={words.text('block.reason_help')}>
                        {(control) => <Textarea {...control} className="min-h-18" value={reason} onChange={(event) => setReason(event.target.value)} />}
                    </Field>
                    <div>
                        <Button destructive className="admin-customer-block__block" onClick={() => setAsking(true)}>
                            {words.text('block.block')}
                        </Button>
                    </div>
                    <Confirm
                        open={asking}
                        title={words.text('block.confirm_title')}
                        name="admin-block-prompt"
                        confirmLabel={words.text('block.confirm')}
                        busyLabel={words.text(BLOCKING)}
                        pending={words.text('block.pending')}
                        busy={write.inFlight === 'block'}
                        onConfirm={blockIt}
                        onDismiss={() => setAsking(false)}
                    >
                        <p className="admin-block-prompt__body">{words.text('block.confirm_body')}</p>
                    </Confirm>
                </>
            ) : (
                <>
                    <p className="admin-customer-block__state font-medium text-destructive">{words.text('block.since', { when: formatInstant(block.blocked_at, clock) })}</p>
                    <p className={block.reason === null ? 'admin-customer-block__reason text-muted-foreground' : 'admin-customer-block__reason whitespace-pre-line break-words'}>{block.reason ?? words.text('block.no_reason')}</p>
                    <div>
                        <Button className="admin-customer-block__unblock" busy={write.inFlight === 'unblock'} busyLabel={words.text(UNBLOCKING)} onClick={unblock}>
                            {words.text('block.unblock')}
                        </Button>
                    </div>
                </>
            )}
        </section>
    );
}
