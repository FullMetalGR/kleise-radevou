import { Head, useForm, usePage, usePoll } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { fill } from '../../../booking/placeholders';
import type { CentralProps, SignupStrings } from '@/types';

/*
 * Where the signup waits. Until the owner's link is clicked the page asks the person to check
 * their email, naming the address the link went to only to the browser that holds the signup, the
 * one carrying its cookie, and "the address you gave" to any other. Once the link is clicked it
 * says the business is being set up. Either way it polls by partial reload every few seconds, and
 * the server answers the sign-in page on the subdomain as an external location the moment the row
 * is active. The business is named only to the holder as well. Before the click a Resend control
 * posts the slug's resend, busy while the answer is on its way; the server answers this page again
 * with one sentence in the flash, whatever it found, and the sentence shows under the control.
 * Every sentence here is the server's, the strings prop the central adapter shares in the
 * request's language, its placeholders filled through the one helper; the page holds none of its
 * own. The control posts where the server says, the resend's path in the page's language.
 */

export const POLL_MS = 3000;

type Props = { business: string | null; email: string | null; verified: boolean; resend: string; address: string; status: string };

type Worded = { strings: SignupStrings };

export function PendingNotice({ strings, business, email, verified, address }: Worded & Pick<Props, 'business' | 'email' | 'verified' | 'address'>) {
    const words = strings.pending;

    if (!verified) {
        return (
            <div className="admin-signup-pending flex flex-col gap-3" role="status" aria-live="polite">
                <h1 className="text-2xl font-semibold">{words.waiting_heading}</h1>
                <p>{fill(words.waiting, { email: email ?? words.your_address, business: business ?? words.your_business, address })}</p>
            </div>
        );
    }

    return (
        <div className="admin-signup-pending flex flex-col gap-3" role="status" aria-live="polite">
            <h1 className="text-2xl font-semibold">{fill(words.verified_heading, { business: business ?? words.your_business })}</h1>
            <p>{fill(words.verified, { address })}</p>
        </div>
    );
}

export function ResendControl({ strings, busy, notice, onResend }: Worded & { busy: boolean; notice: string | null; onResend: () => void }) {
    return (
        <div className="flex flex-col items-start gap-2">
            <Button className="admin-signup-resend" busy={busy} busyLabel={strings.pending.resending} onClick={onResend}>
                {strings.pending.resend}
            </Button>
            {notice !== null && (
                <p className="admin-signup-resent text-sm" role="status">
                    {notice}
                </p>
            )}
        </div>
    );
}

export default function Pending({ business, email, verified, resend, address }: Props) {
    usePoll(POLL_MS, { only: ['status', 'verified'] });
    const form = useForm({});
    const { flash, strings } = usePage<CentralProps>().props;

    return (
        <main className="mx-auto flex max-w-md flex-col gap-6 px-6 py-12">
            <Head title={verified ? strings.pending.verified_title : strings.pending.waiting_title} />
            <PendingNotice strings={strings} business={business} email={email} verified={verified} address={address} />
            {!verified && (
                <ResendControl
                    strings={strings}
                    busy={form.processing}
                    notice={flash?.message ?? null}
                    onResend={() => form.post(resend, { preserveScroll: true })}
                />
            )}
        </main>
    );
}
