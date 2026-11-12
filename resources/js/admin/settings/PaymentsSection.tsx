import { router } from '@inertiajs/react';
import { type KeyboardEvent, type ReactNode, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field, HelpLine, useFocusFirstFailing } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { Notice, type NoticeKind } from '@/components/ui/notice';
import { useCopy } from '@/lib/copy';
import type { TenantClock } from '@/lib/format';
import type { Send } from '@/lib/one-write';
import { placed, type ShellKey, useShell } from '@/lib/strings';
import type { Failure } from '@/lib/visits';
import {
    CASH_SENTENCE,
    checkedSentence,
    type ConnectionState,
    type Cost,
    costOf,
    credentialProblems,
    credentialsBody,
    DEGRADE_SENTENCE,
    feeInForceOf,
    informationSentence,
    MASK,
    MODE_NAMES,
    modeLine,
    NOT_OFFERED,
    type PaymentAction,
    type PaymentConnection,
    paymentFailureSentence,
    type PaymentMode,
    type PaymentProvider,
    type Payments,
    reasonOf,
    standingSentence,
    stateSentence,
    storedSecretLabels,
} from './payments';
import { type PaymentsKey, usePayments } from './words';

/*
 * The Payments section of Settings, generated from the prop's descriptors: the same components
 * render whatever the registry holds, two fields or three, and none names a provider. While
 * nothing is connected it says so loudly, lists each provider with its modes and what each costs,
 * and opens an own-mode credential form from the descriptor's fields. While a connection exists
 * it leads with it: the provider, the mode, the state's own sentence with what the provider said
 * verbatim, and the last test on the tenant's clock, all read from the stored row; the webhook
 * URL with copy and change; Test connection, switch off and back on, Replace and Disconnect, the
 * last behind a confirmation naming what stops, as Change URL is behind one naming that the
 * provider must be told the new address. Platform mode is one button that opens the provider's
 * hosted onboarding, and a pending or restricted platform row offers to resume it, as an active
 * one does whose provider still lists information it needs; a row made through us states the fee
 * in force under its mode. The platform button and the resume are answered with a location, which
 * Inertia's router follows by assigning the window's address before it ends the visit, and the
 * page's one write stays in flight from that answer until the page goes, so every control stays
 * held for the moment before the provider's page replaces this one; a page the browser brings
 * back from its cache on Back is live again (the one-write hook).
 *
 * Nothing is optimistic: each write is the page's visit, which holds every other write while it is
 * in flight, shows the pressed control busy, and lets the answer arrive through the page's props. A
 * 4xx is a worded answer, so every refusal of the section, the live calls' shared limit among them,
 * is its sentence in a warning notice with role status in the section, cleared by the next press,
 * and repeated inside a confirmation it left open; only a transport failure or a 5xx is an error
 * toast. Secrets are write-only: a stored one shows as a mask, never as a value, and Replace opens
 * the whole set again with every field empty, since a save is the whole set.
 *
 * The section sits inside the Settings form, the one form of the Editor, so no form nests here:
 * Enter in a credential input is caught and runs this section's save, never the settings save
 * that implicit submission would otherwise send.
 *
 * The words are keys of the payments section, read through the words the page provides, every
 * sentence naming the provider whole with :provider; Keep it is the kit's, and the copy's line
 * the shell's copy control's.
 */

const PATH = '/admin/settings/payments';

const DISCONNECT_SENTENCE = 'disconnect.body' satisfies PaymentsKey;

const REPLACE_SENTENCE = 'credentials.replace' satisfies PaymentsKey;

const COPY_FAILED = 'webhook.copy_failed' satisfies PaymentsKey;

/** The confirms' Keep it, the kit's. */
const KEEP = 'kit.confirm.keep' satisfies ShellKey;

const STATE_KINDS: Record<ConnectionState, NoticeKind> = { pending: 'info', active: 'success', restricted: 'warning', disabled: 'info' };

/** One of the section's writes as the page runs it: busy, holding the others, its refusal shown here. */
type PaymentVisit = (action: PaymentAction, onSuccess: () => void, send: Send) => void;

/** The page's one visit as the one-write hook runs it, handed this section's wording of a refusal. */
type PageVisit = (action: PaymentAction, word: (failure: Failure) => string | null, onSuccess: () => void, send: Send) => void;

type Props = {
    payments: Payments;
    clock: TenantClock;
    /** The write in flight on the page, this section's or another's, or null. */
    inFlight: string | null;
    /** The sentence for the write in flight on the page, or null. */
    wait: string | null;
    visit: PageVisit;
};

/** A control's busy and held state: busy while its own write is in flight, disabled while another is. */
type Control = { busy: boolean; disabled: boolean };

function nothing(): void {}

export function PaymentsSection({ payments, clock, inFlight, wait, visit: page }: Props) {
    const [open, setOpen] = useState<string | null>(null);
    const [pressed, setPressed] = useState<string | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const words = usePayments();
    const control = (action: PaymentAction): Control => ({ busy: inFlight === action, disabled: inFlight !== null && inFlight !== action });
    const { connection, providers } = payments;
    const platform = (provider: PaymentProvider): Control => ({ busy: inFlight === 'platform' && pressed === provider.slug, disabled: inFlight !== null && !(inFlight === 'platform' && pressed === provider.slug) });

    // A 4xx is a worded answer and presents politely: a warning notice with role status, never
    // an alert. The next press clears it. A transport failure or a 5xx is the error toast.
    const word = (failure: Failure): string | null => {
        if (failure.status !== null && failure.status >= 400 && failure.status < 500) {
            setRefusal(paymentFailureSentence(failure, words));

            return null;
        }

        return paymentFailureSentence(failure, words);
    };
    const visit: PaymentVisit = (action, onSuccess, send) => {
        setRefusal(null);
        page(action, word, onSuccess, send);
    };

    const connectPlatform = (provider: PaymentProvider) => {
        setPressed(provider.slug);
        visit('platform', nothing, (options) => router.post(`${PATH}/platform`, { provider: provider.slug }, options));
    };

    const form = (provider: PaymentProvider, replacing: boolean): ReactNode =>
        open === provider.slug ? (
            <CredentialForm
                provider={provider}
                replacing={replacing}
                control={control('connect')}
                onSave={(values) => visit('connect', () => setOpen(null), (options) => router.post(PATH, credentialsBody(provider, values), options))}
                onCancel={() => setOpen(null)}
            />
        ) : null;

    return (
        <div className="admin-payments flex flex-col gap-4 sm:col-span-2">
            {refusal !== null && (
                <Notice kind="warning" className="admin-payments__refusal">
                    {refusal}
                </Notice>
            )}
            {connection === null ? (
                <ProviderList providers={providers} held={inFlight !== null} open={open} onOpen={setOpen} platform={platform} onPlatform={connectPlatform} form={(provider) => form(provider, false)} />
            ) : (
                <ConnectionView connection={connection} provider={providers.find((candidate) => candidate.slug === connection.provider)} clock={clock} control={control} visit={visit} open={open} onOpen={setOpen} form={(provider) => form(provider, true)} refusal={refusal} />
            )}
            {wait !== null && (
                <HelpLine className="admin-payments__wait" role="status">
                    {wait}
                </HelpLine>
            )}
            <HelpLine className="admin-payments-cash">{words.text(CASH_SENTENCE)}</HelpLine>
        </div>
    );
}

type ListProps = {
    providers: PaymentProvider[];
    held: boolean;
    open: string | null;
    onOpen: (slug: string) => void;
    platform: (provider: PaymentProvider) => Control;
    onPlatform: (provider: PaymentProvider) => void;
    form: (provider: PaymentProvider) => ReactNode;
};

/** Nothing connected: the degrade line, then each provider with its modes and what each costs, before any is chosen. */
function ProviderList({ providers, held, open, onOpen, platform, onPlatform, form }: ListProps) {
    const words = usePayments();

    return (
        <>
            <Notice kind="warning" className="admin-payments-degrade">
                {words.text(DEGRADE_SENTENCE)}
            </Notice>
            <ul className="admin-payments-providers flex flex-col gap-3">
                {providers.map((provider) => (
                    <li key={provider.slug} className="admin-payments-provider flex flex-col gap-2 rounded-lg border p-3" data-provider={provider.slug}>
                        <h3 className="admin-payments-provider__name font-medium">{provider.name}</h3>
                        <ul className="flex flex-col gap-2">
                            {provider.modes.map((mode) => (
                                <ModeRow key={mode.mode} mode={mode} provider={provider} disabled={held || open === provider.slug} onOwn={() => onOpen(provider.slug)} platform={platform(provider)} onPlatform={() => onPlatform(provider)} />
                            ))}
                        </ul>
                        {form(provider)}
                    </li>
                ))}
            </ul>
        </>
    );
}

type ModeProps = { mode: PaymentMode; provider: PaymentProvider; disabled: boolean; onOwn: () => void; platform: Control; onPlatform: () => void };

/**
 * One mode of one provider: how it connects, what it costs as the prop states it with the
 * provider's own processing rate linked to the rate it publishes, and its control. A mode the
 * prop does not offer reads as not offered. Own mode opens the credential form; platform mode's
 * one button posts the provider, busy until the answer, a location the router follows to the
 * provider's hosted onboarding.
 */
function ModeRow({ mode, provider, disabled, onOwn, platform, onPlatform }: ModeProps) {
    const words = usePayments();
    const cost = costOf(mode, provider.name, words);
    const name: PaymentsKey | undefined = MODE_NAMES[mode.mode];

    return (
        <li className={`admin-payments-mode admin-payments-mode--${mode.mode} flex flex-wrap items-center gap-x-3 gap-y-1`}>
            <span className="admin-payments-mode__name text-sm font-medium">{name === undefined ? mode.mode : words.text(name)}</span>
            <span className="admin-payments-mode__cost text-sm">
                {placed(cost.line, {
                    rate: (
                        <a className="admin-payments-mode__rate underline" href={provider.rate_url} target="_blank" rel="noreferrer">
                            {cost.rate}
                        </a>
                    ),
                })}
            </span>
            {!mode.offered ? (
                <span className="admin-payments-mode__unoffered text-sm text-muted-foreground">{words.text(NOT_OFFERED)}</span>
            ) : mode.mode === 'own' ? (
                <Button className="admin-payments-own" disabled={disabled} onClick={onOwn}>
                    {words.text('buttons.own', { provider: provider.name })}
                </Button>
            ) : (
                <Button variant="primary" className="admin-payments-platform" {...platform} busyLabel={words.text('buttons.opening')} onClick={onPlatform}>
                    {words.text('buttons.platform', { provider: provider.name })}
                </Button>
            )}
        </li>
    );
}

type FormProps = {
    provider: PaymentProvider;
    replacing: boolean;
    control: Control;
    onSave: (values: Record<string, string>) => void;
    onCancel: () => void;
};

/**
 * The own-mode credential form, built from the descriptor's fields with their labels and help; a
 * secret field is a password input that starts empty and never holds a stored value. A save is
 * the whole set: the form's own checks refuse a missing or overlong field in the server's words,
 * marking the fields and moving focus to the first failing one, and send nothing. The server's
 * refusals carry one sentence and no field map, so they mark no field and read as the section's
 * notice.
 */
function CredentialForm({ provider, replacing, control, onSave, onCancel }: FormProps) {
    const [values, setValues] = useState<Record<string, string>>({});
    const [attempted, setAttempted] = useState(false);
    const [refusals, setRefusals] = useState(0);
    const container = useFocusFirstFailing<HTMLDivElement>(refusals);
    const words = usePayments();
    const problems = credentialProblems(provider.fields, values, words);
    const heading = `payments-${provider.slug}-credentials`;

    const save = () => {
        if (control.busy || control.disabled) {
            return;
        }

        if (Object.keys(problems).length > 0) {
            setAttempted(true);
            setRefusals((count) => count + 1);

            return;
        }

        onSave(values);
    };

    const enter = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            save();
        }
    };

    return (
        <div ref={container} role="group" aria-labelledby={heading} className="admin-payments-credentials flex flex-col gap-3 rounded-lg border p-3">
            <p id={heading} className="admin-payments-credentials__title font-medium">
                {words.text(replacing ? 'credentials.replace_title' : 'credentials.title', { provider: provider.name })}
            </p>
            {replacing && <HelpLine className="admin-payments-credentials__replace">{words.text(REPLACE_SENTENCE)}</HelpLine>}
            {provider.fields.map((field) => (
                <Field key={field.name} id={`payments-${provider.slug}-${field.name}`} label={field.label} help={field.help} error={attempted ? problems[field.name] : undefined}>
                    {(attributes) => (
                        <TextInput
                            {...attributes}
                            type={field.secret ? 'password' : 'text'}
                            autoComplete={field.secret ? 'new-password' : 'off'}
                            spellCheck={false}
                            value={values[field.name] ?? ''}
                            disabled={control.busy}
                            onKeyDown={enter}
                            onChange={(event) => setValues({ ...values, [field.name]: event.target.value })}
                        />
                    )}
                </Field>
            ))}
            <div className="flex flex-wrap items-center gap-3">
                <Button variant="primary" className="admin-payments-save" busy={control.busy} busyLabel={words.text('buttons.saving')} disabled={control.disabled} onClick={save}>
                    {words.text('buttons.save')}
                </Button>
                <Button variant="tertiary" className="admin-payments-cancel" disabled={control.busy} onClick={onCancel}>
                    {words.text('buttons.cancel')}
                </Button>
            </div>
        </div>
    );
}

type ConnectionProps = {
    connection: PaymentConnection;
    /** The connection's provider as the registry declares it, or nothing where this build no longer registers it. */
    provider: PaymentProvider | undefined;
    clock: TenantClock;
    control: (action: PaymentAction) => Control;
    visit: PaymentVisit;
    open: string | null;
    onOpen: (slug: string) => void;
    form: (provider: PaymentProvider) => ReactNode;
    /** The section's worded refusal, repeated inside a confirmation it left open. */
    refusal: string | null;
};

/**
 * A connection that exists, read from the stored row and never from the provider on render: who,
 * how, the state's sentence with what the provider said verbatim, the last test, the stored
 * secrets as masks, the webhook URL in own mode, and the controls the state and mode offer. A test
 * and a URL change are own mode's, the provider answering for a platform connection: under a
 * platform row the line is where the provider last left the onboarding, a pending or restricted
 * one offers to resume it, and the fee in force stands under the mode. An active platform row
 * whose provider still lists information it needs says so in one line in place of quoting the
 * list, linking the provider's page through the same onboarding wherever this build still
 * registers the provider.
 */
function ConnectionView({ connection, provider, clock, control, visit, open, onOpen, form, refusal }: ConnectionProps) {
    const own = connection.mode === 'own';
    const words = usePayments();
    const post = (action: PaymentAction, path: string) => () => visit(action, nothing, (options) => router.post(path, {}, options));

    return (
        <div className="admin-payments-connection flex flex-col gap-3" data-state={connection.state}>
            <div>
                <h3 className="admin-payments-connection__name font-medium">{connection.name}</h3>
                <HelpLine className="admin-payments-connection__mode">{modeLine(connection.mode, words)}</HelpLine>
                {connection.fee !== null && <FeeInForce cost={feeInForceOf(connection.fee, connection.name, words)} rateUrl={provider?.rate_url} />}
            </div>
            <Standing connection={connection} registered={provider !== undefined} control={control('resume')} onResume={post('resume', `${PATH}/platform/resume`)} />
            <p className="admin-payments-checked text-sm">{own ? checkedSentence(connection, clock, words) : standingSentence(connection, clock, words)}</p>
            <StoredSecrets labels={storedSecretLabels(provider, connection.stored_secrets)} />
            {own && connection.webhook_url !== null && <WebhookUrl url={connection.webhook_url} name={connection.name} control={control('rotate')} visit={visit} refusal={refusal} />}
            <ConnectionControls connection={connection} provider={provider} control={control} visit={visit} post={post} open={open} onOpen={onOpen} refusal={refusal} />
            {provider !== undefined && form(provider)}
        </div>
    );
}

type StandingProps = { connection: PaymentConnection; registered: boolean; control: Control; onResume: () => void };

/**
 * The state's sentence with what the provider said verbatim, or, on an active row made through
 * us whose provider still lists information it needs, the one line saying so in place of the
 * list, with the provider's page linked through a fresh onboarding link where this build still
 * registers the provider.
 */
function Standing({ connection, registered, control, onResume }: StandingProps) {
    const reason = connection.information_due ? null : reasonOf(connection.requirements);
    const words = usePayments();

    return (
        <>
            <Notice kind={STATE_KINDS[connection.state] ?? 'info'} className="admin-payments-state">
                <span className="admin-payments-state__sentence">{stateSentence(connection, words)}</span>
                {reason !== null && <q className="admin-payments-reason mt-1 block font-mono">{reason}</q>}
            </Notice>
            {connection.information_due && (
                <p className="admin-payments-due text-sm">
                    <span className="admin-payments-due__sentence">{informationSentence(connection.name, words)}</span>{' '}
                    {registered && (
                        <Button variant="link" className="admin-payments-due__open" {...control} busyLabel={words.text('buttons.opening')} onClick={onResume}>
                            {words.text('give', { provider: connection.name })}
                        </Button>
                    )}
                </p>
            )}
        </>
    );
}

type ControlsProps = Pick<ConnectionProps, 'connection' | 'provider' | 'control' | 'visit' | 'open' | 'onOpen' | 'refusal'> & { post: (action: PaymentAction, path: string) => () => void };

/** The controls the state and the mode offer: resume while unfinished or wanting, the test and Replace in own mode, the switch, and Disconnect. */
function ConnectionControls({ connection, provider, control, visit, post, open, onOpen, refusal }: ControlsProps) {
    const own = connection.mode === 'own';
    const resumable = !own && (connection.state === 'pending' || connection.state === 'restricted');
    const words = usePayments();
    const switched = switchOf(connection.state);

    return (
        <div className="admin-payments-controls flex flex-wrap items-center gap-3">
            {resumable && (
                <Button variant="primary" className="admin-payments-resume" {...control('resume')} busyLabel={words.text('buttons.opening')} onClick={post('resume', `${PATH}/platform/resume`)}>
                    {words.text('buttons.resume')}
                </Button>
            )}
            {own && (
                <Button className="admin-payments-test" {...control('test')} busyLabel={words.text('buttons.testing')} onClick={post('test', `${PATH}/test`)}>
                    {words.text('buttons.test')}
                </Button>
            )}
            <Button className={`admin-payments-${switched.action}`} {...control(switched.action)} busyLabel={words.text(switched.busyLabel)} onClick={post(switched.action, `${PATH}/${switched.action}`)}>
                {words.text(switched.label)}
            </Button>
            {own && provider !== undefined && (
                <Button className="admin-payments-replace" disabled={control('connect').disabled || control('connect').busy || open === provider.slug} onClick={() => onOpen(provider.slug)}>
                    {words.text('buttons.replace')}
                </Button>
            )}
            <Disconnect name={connection.name} control={control('disconnect')} visit={visit} refusal={refusal} />
        </div>
    );
}

/** The tenant's switch: back on for a row switched off here, off for any other. */
function switchOf(state: ConnectionState): { action: 'enable' | 'disable'; label: PaymentsKey; busyLabel: PaymentsKey } {
    return state === 'disabled' ? { action: 'enable', label: 'buttons.enable', busyLabel: 'buttons.enabling' } : { action: 'disable', label: 'buttons.disable', busyLabel: 'buttons.disabling' };
}

/** The fee in force on a row made through us, the provider's own processing rate linked to the rate it publishes where this build still registers it. */
function FeeInForce({ cost, rateUrl }: { cost: Cost; rateUrl: string | undefined }) {
    return (
        <p className="admin-payments-fee text-sm">
            {placed(cost.line, {
                rate:
                    rateUrl === undefined ? (
                        cost.rate
                    ) : (
                        <a className="admin-payments-fee__rate underline" href={rateUrl} target="_blank" rel="noreferrer">
                            {cost.rate}
                        </a>
                    ),
            })}
        </p>
    );
}

/** The stored secrets of a connection, each a mask and never a value or an input. */
function StoredSecrets({ labels }: { labels: string[] }) {
    const words = usePayments();

    if (labels.length === 0) {
        return null;
    }

    return (
        <div className="admin-payments-secrets flex flex-col gap-1 text-sm">
            <p className="text-muted-foreground">{words.text('credentials.stored')}</p>
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                {labels.map((label) => (
                    <div key={label} className="admin-payments-secret contents">
                        <dt className="admin-payments-secret__label">{label}</dt>
                        <dd className="admin-payments-secret__mask font-mono">{MASK}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/** A refusal inside the confirmation whose press it answered, which covers the section's notice. */
function ConfirmRefusal({ refusal }: { refusal: string | null }) {
    return refusal === null ? null : (
        <Notice kind="warning" className="admin-payments-confirm__refusal mb-2">
            {refusal}
        </Notice>
    );
}

/**
 * Disconnect, behind a confirmation naming what stops. While the removal is in flight no path
 * dismisses the dialog; it closes on the landed answer, and a refusal leaves it open, saying
 * the refusal inside it.
 */
function Disconnect({ name, control, visit, refusal }: { name: string; control: Control; visit: PaymentVisit; refusal: string | null }) {
    const [confirming, setConfirming] = useState(false);
    const [pressed, setPressed] = useState(false);
    const ask = () => {
        setPressed(false);
        setConfirming(true);
    };
    const words = usePayments();
    const shell = useShell();

    return (
        <>
            <Button variant="tertiary" destructive className="admin-payments-disconnect" disabled={control.disabled} onClick={ask}>
                {words.text('disconnect.button')}
            </Button>
            <Modal
                open={confirming}
                title={words.text('disconnect.title', { provider: name })}
                onClose={control.busy ? nothing : () => setConfirming(false)}
                className="admin-payments-confirm"
                footer={
                    <>
                        <Button className="admin-payments-confirm__keep" disabled={control.busy} onClick={() => setConfirming(false)}>
                            {shell.text(KEEP)}
                        </Button>
                        <Button
                            variant="primary"
                            destructive
                            className="admin-payments-confirm__disconnect"
                            {...control}
                            busyLabel={words.text('disconnect.busy')}
                            onClick={() => {
                                setPressed(true);
                                visit('disconnect', () => setConfirming(false), (options) => router.delete(PATH, options));
                            }}
                        >
                            {words.text('disconnect.button')}
                        </Button>
                    </>
                }
            >
                <ConfirmRefusal refusal={pressed ? refusal : null} />
                <p className="admin-payments-confirm__body text-sm">{words.text(DISCONNECT_SENTENCE)}</p>
            </Modal>
        </>
    );
}

type WebhookProps = { url: string; name: string; control: Control; visit: PaymentVisit; refusal: string | null };

/**
 * Own mode's webhook URL to paste into the provider's dashboard, with a copy control and a change
 * control that leaves the credentials alone. The change is behind a confirmation in Disconnect's
 * shape naming that the provider must be told the new address: Keep and Escape leave the token
 * alone, no path dismisses it while the change is in flight, it closes on the landed answer and a
 * refusal leaves it open.
 */
function WebhookUrl({ url, name, control, visit, refusal }: WebhookProps) {
    const [confirming, setConfirming] = useState(false);
    const [pressed, setPressed] = useState(false);
    const ask = () => {
        setPressed(false);
        setConfirming(true);
    };
    const words = usePayments();
    const shell = useShell();
    const { said: copied, copy } = useCopy(words.text(COPY_FAILED), false);

    return (
        <div className="admin-payments-webhook flex flex-col gap-1">
            <p className="text-sm font-medium">{words.text('webhook.title')}</p>
            <code className="admin-payments-webhook__url break-all rounded bg-muted px-2 py-1 text-sm">
                {url}
            </code>
            <HelpLine className="admin-payments-webhook__help">{words.text('webhook.help', { provider: name })}</HelpLine>
            <div className="flex flex-wrap items-center gap-3">
                <Button className="admin-payments-copy" onClick={() => copy(url)}>
                    {words.text('webhook.copy')}
                </Button>
                <Button className="admin-payments-rotate" disabled={control.disabled} onClick={ask}>
                    {words.text('webhook.change')}
                </Button>
                {copied !== null && (
                    <p className="admin-payments-copied text-sm text-muted-foreground" role="status">
                        {copied}
                    </p>
                )}
            </div>
            <Modal
                open={confirming}
                title={words.text('webhook.confirm_title')}
                onClose={control.busy ? nothing : () => setConfirming(false)}
                className="admin-payments-rotate-confirm"
                footer={
                    <>
                        <Button className="admin-payments-rotate-confirm__keep" disabled={control.busy} onClick={() => setConfirming(false)}>
                            {shell.text(KEEP)}
                        </Button>
                        <Button
                            variant="primary"
                            className="admin-payments-rotate-confirm__change"
                            {...control}
                            busyLabel={words.text('webhook.changing')}
                            onClick={() => {
                                setPressed(true);
                                visit('rotate', () => setConfirming(false), (options) => router.post(`${PATH}/rotate`, {}, options));
                            }}
                        >
                            {words.text('webhook.change')}
                        </Button>
                    </>
                }
            >
                <ConfirmRefusal refusal={pressed ? refusal : null} />
                <p className="admin-payments-rotate-confirm__body text-sm">{words.text('webhook.confirm_body', { provider: name })}</p>
            </Modal>
        </div>
    );
}
