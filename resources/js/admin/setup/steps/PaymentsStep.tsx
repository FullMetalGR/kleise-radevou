import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { clockOf } from '@/lib/format';
import { useOneWrite } from '@/lib/one-write';
import { useShell } from '@/lib/strings';
import { PaymentsSection } from '@/settings/PaymentsSection';
import { PAYMENT_WAIT, type PaymentAction, type Payments } from '@/settings/payments';
import { PaymentsProvider, type PaymentsWords, paymentsWordsOf } from '@/settings/words';
import type { PaymentsStrings, SharedProps } from '@/types';
import { useStepDirty, useStepPage } from '../step';

/*
 * The Online payments step: Settings' Payments section whole, over the prop and the words the
 * server hands that screen, so every choice, state and refusal reads as it does there and every
 * write but two goes to Settings' own routes, coming back to Home. Connect through us and Continue
 * onboarding post to the step's own routes instead, whose provider brings the owner back to Home
 * with the wizard open on this step. The primary is the wizard's Next; Skip for now is the
 * wizard's, while the step is open.
 *
 * The section keeps what is typed into a credentials form to itself, so the step counts an open
 * form as an unsaved edit: the wizard asks before it is left, until the form is cancelled or its
 * save has landed.
 */

/** Connect through us from the step, POST, the provider's slug in the body; Continue onboarding under it. */
export const ONBOARDING_URL = '/admin/setup/payments/platform';

type PaymentsStepStrings = { name: string; help: string };

type PageProps = SharedProps & { strings: SharedProps['strings'] & { payments?: PaymentsStrings } };

/** Each payments write's wait, read in the payments section. */
function waitsOf(words: PaymentsWords): Record<PaymentAction, string> {
    return Object.fromEntries(Object.entries(PAYMENT_WAIT).map(([action, key]) => [action, words.text(key)])) as Record<PaymentAction, string>;
}

export function PaymentsStep() {
    const { data, words } = useStepPage<Payments, PaymentsStepStrings>();
    const { props } = usePage<PageProps>();
    const payments = paymentsWordsOf(props.strings, useShell().language);
    const { inFlight, pending, visit } = useOneWrite<PaymentAction>(waitsOf(payments));
    const [typing, setTyping] = useState(false);

    useStepDirty(typing);

    if (data === null) {
        return null;
    }

    return (
        <div className="admin-setup-payments flex flex-col gap-3">
            <p className="admin-setup-payments__help text-sm text-muted-foreground">{words.text('help')}</p>
            <PaymentsProvider words={payments}>
                <PaymentsSection payments={data} clock={clockOf(props)} inFlight={inFlight} wait={pending} visit={visit} onboarding={ONBOARDING_URL} onForm={setTyping} />
            </PaymentsProvider>
        </div>
    );
}
