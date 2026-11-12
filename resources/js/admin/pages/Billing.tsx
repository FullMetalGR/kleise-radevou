import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { namedFeatures } from '@/lib/features';
import { clockOf, formatAmount, formatInstant, type TenantClock } from '@/lib/format';
import { listOf } from '@/lib/names';
import { placed, useShell } from '@/lib/strings';
import { NOT_DONE, owningFailure } from '@/lib/visits';
import { BillingProvider, type BillingWords, billingWordsOf, useBilling } from '@/settings/words';
import { useToasts } from '@/toast/toasts';
import type { BillingStrings, SharedProps } from '@/types';

/*
 * The Billing screen: the page an owner comes to when something is wrong, so it renders from props
 * and never fetches, and is right on the first paint. The server words the state notice, one whole
 * sentence per state and the paragraph of what is paused, and the plan card, the Change plan
 * control and the reason either is missing; this renders them verbatim and fills nothing into them.
 * A closing account's notice is the server's too, in place of the state's whatever the state: the
 * day it closes, that everything in it is deleted then, and the way back, the checkout's plan
 * choice rendered inside it where the state offers one. The two ends are this screen's own
 * paragraphs, spelled from the shared subscription prop alone: the grace end inside grace, and the
 * trial's end in a trial and in a trial that ended unpaid, canceled with the end still on the prop.
 * Both are date and time in the tenant's zone and formats, the admin's shape for a wire timestamp:
 * each closes at an hour of its last day and the hourly lapse follows, so the date alone would read
 * as the whole day. The narrowing record is named beside the plan; a trial's plan card is the Free
 * trial's full set, with no price. A free tenant's page is its one success sentence, the Plan
 * section left out where it would hold nothing, no plan card, no record, no reason and no change.
 *
 * Nothing is optimistic. Each write shows its busy state on the pressed control and disables the
 * others; the server's answer arrives as the redirect back and its flash, the refusal's sentence
 * included. A press past the three writes' own limit answers a worded 429, shown as a warning
 * notice with role status under the heading and cleared by the next press, never an alert or a
 * toast; a refused field's 400 envelope, which carries its own sentence, and a failure the server
 * could not word are an error toast that stays. The portal answers an external redirect, which the
 * router follows off the admin, and so does the checkout: the control the billing prop offers in a
 * trial, after one and in canceled, labelled and captioned by the server, Choose your plan,
 * Subscribe, Subscribe again or, with a checkout open, Continue to checkout under the sentence
 * saying the new subscription is on its way. Where the prop names the plan the press posts it;
 * where it carries a plan choice instead, the press opens the choice, each plan with its name and
 * price as the plan card spells them and its own button posting it, a plan the roster refuses
 * disabled with the refusal's sentence beside it.
 *
 * The screen's own words are keys of the billing section, which the controller names, read through
 * the words the page provides: a price is one line with its amount in the tenant's money and the
 * user's language, each end one line with the instant, and a plan beside its price one whole line
 * whose parts the screen places as nodes.
 */

export const PLAN_PATH = '/admin/billing/plan';

export const PORTAL_PATH = '/admin/billing/portal';

export const CHECKOUT_PATH = '/admin/billing/checkout';

export const STAFF_PATH = '/admin/staff';

type Money = { amount: number; currency: string };

/** The plan card: a catalogue plan with its price a month, or a trial's full set with none. */
export type BillingPlan = { name: string; includes: string[] } & (Money | { amount: null; currency: null });

/** One plan of the checkout's plan choice, with the refusal's sentence while the roster refuses it. */
export type BillingOption = Money & { plan: string; name: string; blocked: string | null };

export type BillingChange = Money & { plan: string; adds: boolean; name: string; blocked: string | null };

export type BillingProps = {
    notice: { kind: 'success' | 'warning'; sentence: string; paused: string | null };
    plan: BillingPlan | null;
    change: BillingChange | null;
    unavailable: string | null;
    portal: boolean;
    /** The VAT sentence of the payment card, null where no subscriptions row exists. */
    vat: string | null;
    resubscribe: BillingResubscribe | null;
};

/**
 * The deferred prop carrying the tax id line, worded by the server, BillingController::TAX_ID.
 * The router loads it after the first render, and it is absent from the page until then.
 */
export const TAX_ID_PROP = 'tax_id';

/**
 * The checkout's control: its label, the on-its-way sentence while a checkout is open, and either
 * the plan its press posts or the plan choice its press opens, never both.
 */
export type BillingResubscribe = { label: string; pending: string | null } & ({ plan: string; choice: null } | { plan: null; choice: BillingOption[] });

type Props = SharedProps & { billing: BillingProps; [TAX_ID_PROP]?: string; strings: SharedProps['strings'] & { billing?: BillingStrings } };

type Action = 'plan' | 'portal' | 'checkout';

/** Whether a write other than this one is in flight, which disables this one's control. */
function othersBusy(inFlight: Action | null, action: Action): boolean {
    return inFlight !== null && inFlight !== action;
}

/** A plan's price a month, its amount in the tenant's money and the user's language. */
function monthly({ amount, currency }: Money, words: BillingWords): string {
    return words.text('plan.price', { amount: formatAmount(amount, currency, words.locale) });
}

/**
 * Whether the Plan section has anything to say: a plan card, a narrowing record, the reason either
 * control is missing, or Change plan. A free tenant's has none of them, and the section is left out.
 */
function planSectionHolds(billing: BillingProps, narrowed: string[]): boolean {
    return billing.plan !== null || narrowed.length > 0 || billing.unavailable !== null || billing.change !== null;
}

/** The grace end as the notice spells it, inside grace alone. */
function graceEndOf(subscription: SharedProps['subscription'], clock: TenantClock): string | null {
    return subscription.state === 'grace' && subscription.grace_ends_at !== undefined ? formatInstant(subscription.grace_ends_at, clock) : null;
}

/** The trial's end as the notice spells it: ahead inside a trial, behind once a trial ended unpaid. */
function trialEndOf(subscription: SharedProps['subscription'], clock: TenantClock, words: BillingWords): string | null {
    if (subscription.trial_ends_at === undefined) {
        return null;
    }

    const end = formatInstant(subscription.trial_ends_at, clock);

    switch (subscription.state) {
        case 'trial':
            return words.text('trial_end', { end });
        case 'canceled':
            return words.text('trial_ended', { end });
        default:
            return null;
    }
}

/** The control's busy state, which plan a press is posting, and the press itself with the plan it posts. */
type CheckoutProps = { busy: boolean; disabled: boolean; posting: string | null; onPost: (plan: string) => void };

type NoticeProps = { notice: BillingProps['notice']; graceEnds: string | null; trialEnd: string | null; resubscribe: BillingResubscribe | null; checkout: CheckoutProps };

function StateNotice({ notice, graceEnds, trialEnd, resubscribe, checkout }: NoticeProps) {
    const words = useBilling();

    return (
        <Notice kind={notice.kind} className="admin-billing__notice">
            <div className="flex flex-col gap-2">
                <p className="admin-billing__sentence">{notice.sentence}</p>
                {resubscribe !== null && resubscribe.pending !== null && <p className="admin-billing__pending">{resubscribe.pending}</p>}
                {graceEnds !== null && <p className="admin-billing__grace">{words.text('grace_end', { end: graceEnds })}</p>}
                {trialEnd !== null && <p className="admin-billing__trial">{trialEnd}</p>}
                {notice.paused !== null && <p className="admin-billing__paused">{notice.paused}</p>}
                {resubscribe !== null && <CheckoutControl resubscribe={resubscribe} {...checkout} />}
            </div>
        </Notice>
    );
}

/**
 * The checkout's control. Naming a plan, the press posts it. Carrying a choice, the press opens
 * the choice under it, and each plan's own button posts that plan.
 */
function CheckoutControl({ resubscribe, busy, disabled, posting, onPost }: CheckoutProps & { resubscribe: BillingResubscribe }) {
    const words = useBilling();
    const [open, setOpen] = useState(false);

    if (resubscribe.choice === null) {
        const { plan } = resubscribe;

        return (
            <div>
                <Button className="admin-billing__resubscribe" busy={busy} busyLabel={words.text('checkout.busy')} disabled={disabled} onClick={() => onPost(plan)}>
                    {resubscribe.label}
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col items-start gap-3">
            <Button className="admin-billing__resubscribe" aria-expanded={open} aria-controls="billing-choice" disabled={busy || disabled} onClick={() => setOpen((was) => !was)}>
                {resubscribe.label}
            </Button>
            {open && (
                <ul id="billing-choice" className="admin-billing__choice flex flex-col gap-2" aria-label={words.text('checkout.plans')}>
                    {resubscribe.choice.map((option) => (
                        <PlanOption key={option.plan} option={option} busy={busy && posting === option.plan} disabled={disabled || (busy && posting !== option.plan)} onPost={onPost} />
                    ))}
                </ul>
            )}
        </div>
    );
}

function PlanOption({ option, busy, disabled, onPost }: { option: BillingOption; busy: boolean; disabled: boolean; onPost: (plan: string) => void }) {
    const words = useBilling();
    const reason = `billing-choice-${option.plan}-blocked`;

    return (
        <li className="admin-billing__option flex flex-col items-start gap-1" data-plan={option.plan}>
            <p className="text-sm">
                {placed(words.text('checkout.option'), {
                    plan: (
                        <span className="admin-billing__option-name font-medium">
                            {option.name}
                        </span>
                    ),
                    price: (
                        <span className="admin-billing__option-price font-mono">
                            {monthly(option, words)}
                        </span>
                    ),
                })}
            </p>
            <Button
                variant="primary"
                className="admin-billing__option-button"
                busy={busy}
                busyLabel={words.text('checkout.busy')}
                disabled={disabled || option.blocked !== null}
                aria-describedby={option.blocked === null ? undefined : reason}
                onClick={() => onPost(option.plan)}
            >
                {words.text('checkout.choose', { plan: option.name })}
            </Button>
            {option.blocked !== null && (
                <p id={reason} className="admin-billing__option-blocked text-sm">
                    {option.blocked}
                </p>
            )}
        </li>
    );
}

function PlanSummary({ plan }: { plan: BillingPlan }) {
    const words = useBilling();

    return (
        <div className="flex flex-col gap-1">
            <p className="admin-billing__plan-name font-medium">{plan.name}</p>
            {plan.amount !== null && <p className="admin-billing__price font-mono text-sm">{monthly(plan, words)}</p>}
            <ul className="admin-billing__includes list-disc pl-5 text-sm">
                {plan.includes.map((line) => (
                    <li key={line}>{line}</li>
                ))}
            </ul>
        </div>
    );
}

type ControlProps = { busy: boolean; disabled: boolean; onPress: () => void };

function ChangeControl({ change, busy, disabled, onPress }: ControlProps & { change: BillingChange }) {
    const words = useBilling();
    const price = (
        <span className="admin-billing__change-price font-mono">
            {monthly(change, words)}
        </span>
    );

    return (
        <div className="admin-billing__change flex flex-col items-start gap-2 border-t pt-3">
            <p className="text-sm">{placed(words.text(change.adds ? 'change.add_line' : 'change.remove_line'), { plan: change.name, price })}</p>
            <Button variant={change.adds ? 'primary' : 'secondary'} destructive={!change.adds} className="admin-billing__change-button" busy={busy} busyLabel={words.text('change.busy')} disabled={disabled} onClick={onPress}>
                {words.text(change.adds ? 'change.add' : 'change.remove')}
            </Button>
            {change.blocked !== null && (
                <p className="admin-billing__staff-note text-sm">
                    {change.blocked}{' '}
                    <Link href={STAFF_PATH} className="admin-billing__staff-link underline">
                        {words.text('change.staff_link')}
                    </Link>
                </p>
            )}
        </div>
    );
}

/**
 * The payment card: the provider's portal, with the VAT line and, once the router has loaded it,
 * the tax id line above its button; nothing stands in the tax id line's place before it arrives.
 */
function Payment({ busy, disabled, onPress, vat, taxId }: ControlProps & { vat: string | null; taxId: string | undefined }) {
    const words = useBilling();

    return (
        <section className="admin-section admin-billing__payment rounded-xl border p-4" aria-labelledby="billing-payment">
            <h2 id="billing-payment" className="mb-3 font-semibold">
                {words.text('payment.title')}
            </h2>
            <div className="flex flex-col items-start gap-2">
                <p className="text-sm">{words.text('payment.sentence')}</p>
                {vat !== null && <p className="admin-billing__vat text-sm">{vat}</p>}
                {taxId !== undefined && <p className="admin-billing__tax-id text-sm">{taxId}</p>}
                <Button className="admin-billing__portal" busy={busy} busyLabel={words.text('payment.busy')} disabled={disabled} onClick={onPress}>
                    {words.text('payment.button')}
                </Button>
            </div>
        </section>
    );
}

export default function Billing() {
    const { props } = usePage<Props>();
    const { billing, subscription } = props;
    const toasts = useToasts();
    const shell = useShell();
    const words = billingWordsOf(props.strings, shell.language);
    const [inFlight, setInFlight] = useState<Action | null>(null);
    const [limited, setLimited] = useState<string | null>(null);
    // The plan the write in flight posts, from its start to its finish.
    const [posting, setPosting] = useState<string | null>(null);
    const clock = clockOf(props);
    const narrowed = namedFeatures(props.narrowed, shell).map(({ name }) => name);

    const send = (action: Action, path: string, body: Record<string, string>) => {
        if (inFlight !== null) {
            return;
        }

        // Past the writes' own limit the answer is a worded 429: a warning notice with role
        // status, never an alert. The next press clears it.
        const owned = owningFailure((failure) => (failure.status === 429 && failure.sentence !== null ? setLimited(failure.sentence) : toasts.push('error', failure.sentence ?? shell.text(NOT_DONE))), shell);

        setLimited(null);

        router.post(path, body, {
            preserveScroll: true,
            onStart: () => {
                setInFlight(action);
                setPosting(body.plan ?? null);
                owned.onStart();
            },
            onFinish: () => {
                setInFlight(null);
                setPosting(null);
                owned.onFinish();
            },
        });
    };

    const { change, resubscribe } = billing;

    return (
        <BillingProvider words={words}>
            <div className="admin-billing flex flex-col gap-4">
                <h1 className="text-2xl font-semibold">{words.text('title')}</h1>

                {limited !== null && (
                    <Notice kind="warning" className="admin-billing__limited">
                        {limited}
                    </Notice>
                )}

                <StateNotice
                    notice={billing.notice}
                    graceEnds={graceEndOf(subscription, clock)}
                    trialEnd={trialEndOf(subscription, clock, words)}
                    resubscribe={resubscribe}
                    checkout={{
                        busy: inFlight === 'checkout',
                        disabled: othersBusy(inFlight, 'checkout'),
                        posting,
                        onPost: (plan) => send('checkout', CHECKOUT_PATH, { plan }),
                    }}
                />

                {planSectionHolds(billing, narrowed) && (
                    <section className="admin-section admin-billing__plan rounded-xl border p-4" aria-labelledby="billing-plan">
                        <h2 id="billing-plan" className="mb-3 font-semibold">
                            {words.text('plan.title')}
                        </h2>
                        <div className="flex flex-col gap-3">
                            {billing.plan !== null && <PlanSummary plan={billing.plan} />}
                            {narrowed.length > 0 && <p className="admin-billing__narrowed text-sm">{words.text('plan.narrowed', { features: listOf(narrowed, shell.language) })}</p>}
                            {billing.unavailable !== null && <p className="admin-billing__unavailable text-sm text-muted-foreground">{billing.unavailable}</p>}
                            {change !== null && <ChangeControl change={change} busy={inFlight === 'plan'} disabled={othersBusy(inFlight, 'plan')} onPress={() => send('plan', PLAN_PATH, { plan: change.plan })} />}
                        </div>
                    </section>
                )}

                {billing.portal && (
                    <Payment
                        busy={inFlight === 'portal'}
                        disabled={othersBusy(inFlight, 'portal')}
                        onPress={() => send('portal', PORTAL_PATH, {})}
                        vat={billing.vat}
                        taxId={props[TAX_ID_PROP]}
                    />
                )}
            </div>
        </BillingProvider>
    );
}
