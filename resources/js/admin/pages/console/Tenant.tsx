import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Confirm } from '@/components/ui/confirm';
import { Field } from '@/components/ui/field';
import { Textarea } from '@/components/ui/input';
import { statusLabel } from '@/components/ui/status-chip';
import { TableScroll } from '@/components/ui/table-scroll';
import { useShell } from '@/lib/strings';
import { NOT_DONE, owningFailure } from '@/lib/visits';
import { useToasts } from '@/toast/toasts';
import type { ConsoleProps } from '@/types';
import { chosenIn, FeatureBoxes, type FeatureChoice, useFeatureChoice } from './FeatureBoxes';
import { type BookingStatus, NO_PLAN, type PlanName, planWord, STATE_WORDS, STATUS_WORDS, type SubscriptionState, type TenantPlan, type TenantStatus } from './vocabulary';

/*
 * A tenant's detail: one tenant read whole, inside the console's shell, in four sections. The
 * account: the status, the clock and the currency the tenant keeps, the category, the signup, the
 * terms the owner accepted at it with the version's day, or not recorded, and, only where the row
 * holds them, the close beside the day its retention window ends and the suspension's instant and
 * reason. The plan and the subscription: the plan the server derived, floor and staff by the names
 * the server sends from the catalogue, the subscription state, the trial's and the grace's ends,
 * and the billing provider's customer, status and period end where a subscriptions row exists. The
 * people: every user with the role and whether the email is verified. The activity: the active
 * services, the active staff, the bookings by status and the last booking made, none of it for a
 * provisioning row, which has no data of its own yet.
 *
 * Every instant is as the server spelled it on the console's clock, which the top bar names once
 * for the screen, and each vocabulary value is in words. The links go out to the tenant's own
 * origin in a new tab, the booking page and the admin's sign-in, and to the Audit screen narrowed
 * to the tenant.
 *
 * A closed row, which the drop left with no slug, no schema and no users, is the same page read by
 * its id: the account with the instant it closed and the plan and the subscription as the row keeps
 * them, the slug read as released, the people and the activity each a sentence saying they went
 * with it, and no link, no control, no support and no session, since the row has no origin left and
 * nothing on it can be acted on.
 *
 * The suspension's controls: Suspend on an active row alone and Resume on a suspended row alone,
 * each opening the admin's one Confirm. Suspend's holds the reason the owners are mailed, opened
 * empty each time; Resume's names the tenant and that its booking page reopens, and its confirm
 * posts with no body. Nothing is optimistic: the pressed confirm shows its busy state, the overlay
 * cannot be dismissed while its post is in flight, and what the tenant now is arrives with the
 * redirect back to this page and its flash, a refusal's sentence among them as an error toast that
 * stays. A reason the server refuses comes back on the field with its sentence and the modal stays
 * open; a failure the server could not word is an error toast that stays, the reason typed kept.
 *
 * The close and the reopen, on the same Confirm and the same terms: Close beside Suspend or Resume
 * on an active or a suspended row, its confirm holding the reason the owners are mailed as
 * Suspend's does, and saying on a suspended row that the close ends the suspension; Reopen on a
 * closing row in the free shape alone, the plan the server derived as free, its confirm saying the
 * account stays open and posting with no body. A closing row reads "Closing since {instant},
 * deleted on {day}." above the controls, both on the console's clock as the server spelled them, in
 * place of the account's two closing facts, and outside the free shape the sentence naming the
 * owner's Billing screen as its way back, where nothing is offered.
 *
 * The set-password link: beside every user, whatever the role and the row's status, a control that
 * posts no body to that user's link and shows its busy state until the server's answer lands, one
 * send at a time; what was sent arrives with the redirect back and its flash, and a failure the
 * server could not word is an error toast that stays. The console never types or learns a password.
 *
 * Change features: on a tenant in the free shape alone, where the server sends the set the row
 * holds and one choice per feature, a checkbox each and Save, which posts the boxes as one list and
 * shows its busy state until the server's answer lands. The set saved, or the refusal's sentence,
 * arrives with the redirect back and its flash; a list the server refuses comes back under the
 * boxes with its sentence, and a failure the server could not word is an error toast that stays.
 * The boxes start from the server's set and start again from it whenever the server answers a
 * different one. Every other tenant's plan is billing's and the page offers nothing.
 *
 * Open as support: on an active or a closing row alone, since a suspended host answers 503
 * everywhere and a provisioning one has no admin yet, a control that posts no body to the tenant's
 * support and shows its busy state until the server's answer lands. The answer is a location to the
 * tenant's own origin, which the browser follows as a full-page visit, landing on the tenant's Home
 * as support; a refusal arrives with the redirect back and its flash, and a failure the server
 * could not word is an error toast that stays.
 *
 * The open support sessions: on every row, each session opened, unended and unexpired, with its
 * operator, the instant it was opened and the instant it ends as the server spelled them, and End
 * beside it, which posts no body to the session's end and shows its busy state on that row until
 * the server's answer lands, one end at a time. What ended arrives with the redirect back, the list
 * read again and the flash, a session already ended among them as an error toast that stays; a
 * failure the server could not word is one too. A sentence stands in for an empty list.
 */

/** The vocabulary of App\Tenancy\Role. */
type Role = 'owner' | 'manager' | 'staff';

type Person = { id: number; name: string; email: string; role: Role; verified: boolean };

type Activity = {
    services: number;
    staff: number;
    /** Every status of the booking machine in its order, nought where none stands in it. */
    bookings: { status: BookingStatus; count: number }[];
    /** The last booking's creation instant, spelled by the server, or null where none was made. */
    last: string | null;
};

export type TenantDetail = {
    /** The slug, or null on a closed row, whose slug the drop released. */
    slug: string | null;
    name: string;
    status: TenantStatus;
    timezone: string;
    currency: string;
    /** The category's label, or null where the row names none. */
    category: string | null;
    signed_up: string;
    trial_ends: string | null;
    grace_ends: string | null;
    /**
     * The close, the instant its retention window ends and the day it ends, each on the console's
     * clock, or null where the row is not closing and not closed.
     */
    closing: { at: string; retention_ends: string; deleted_on: string } | null;
    /** The instant the drop closed the row, or null where it is not closed. */
    closed: string | null;
    /** The suspension's instant and reason, or null where the row is not suspended. */
    suspension: { at: string; reason: string } | null;
    /**
     * The owner's acceptance of the terms at signup, the instant on the console's clock and the
     * version's day, or null where the row records none.
     */
    terms: { at: string; version: string } | null;
    state: SubscriptionState;
    /** The plan the server derived, or null while the row is provisioning. */
    plan: TenantPlan | null;
    /** The subscriptions row's facts, or null where the row has none. */
    subscription: { customer: string; status: string | null; period_end: string | null } | null;
    users: Person[];
    /** What the tenant's schema holds, or null for a provisioning row, which has none. */
    activity: Activity | null;
};

export type TenantLinks = { hosted_page: string; sign_in: string; audit: string };

/** The set a free tenant's row holds and the choices beside it, or null where the plan is billing's. */
export type TenantFeatures = { current: string[]; offered: FeatureChoice[] } | null;

/** An open support session at the tenant, its two instants spelled by the server on the console's clock. */
export type OpenSupportSession = { id: number; operator: string; opened: string; ends: string };

/** The links, or null on a closed row, which has no origin left and no slug its audit is named by. */
type Props = ConsoleProps & { tenant: TenantDetail; plan_names: PlanName[]; links: TenantLinks | null; features: TenantFeatures; support_sessions: OpenSupportSession[] };

/** Each role in words, one per case of the vocabulary. */
const ROLE_WORDS: Record<Role, string> = {
    owner: 'Owner',
    manager: 'Manager',
    staff: 'Staff',
};

/** What a fact the row does not hold reads as. */
const NONE = 'None';

/** What a billing fact reads as before the provider's first snapshot names it. */
const NOT_YET = 'None yet';

export const NO_SUBSCRIPTION = 'No subscription with the billing provider.';

export const NO_USERS = 'This account has no users.';

export const NO_ACTIVITY = 'Nothing to count yet: the account is still provisioning, so it holds no data of its own.';

export const NO_LAST_ACTIVITY = 'No booking made yet';

/** What the terms fact reads where the row records no acceptance: a console, demo or older row. */
export const TERMS_NOT_RECORDED = 'Not recorded';

/**
 * The version of the terms, a line standing by itself under the instant they were accepted, the
 * day the server spelled.
 */
export const termsVersion = (day: string): string => `Version of ${day}`;

/** What a closed row's slug reads as: the drop released it for a new signup. */
export const SLUG_RELEASED = 'Slug released';

export const CLOSED_USERS = 'This account\'s users were deleted when it closed.';

export const CLOSED_ACTIVITY = 'Nothing to count: everything this account held was deleted when it closed.';

const ALL_TENANTS = 'All tenants';

const HOSTED_PAGE = 'Open the booking page';

const SIGN_IN = 'Open the admin sign-in';

const AUDIT = 'See this tenant\'s audit';

/** What a suspension does, said in the modal before the operator confirms it. */
const SUSPEND_WARNING = 'Every page of this business, the booking page and the admin included, will say that booking is paused until you resume it. Messages about bookings already made keep going to customers.';

const REASON_HELP = 'Emailed to the owners as you write it, up to 500 characters.';

const SUSPEND_PENDING = 'The suspension has been sent. This closes once the server answers.';

/**
 * What a resumption does, said in the confirm before the operator resumes.
 */
export const RESUME_WARNING = 'Every page of this business, the booking page and the admin included, opens again for its customers and its team.';

const RESUME_PENDING = 'The resumption has been sent. This closes once the server answers.';

/** What a close does, said in the modal before the operator confirms it. */
export const CLOSE_WARNING = 'The account closes in thirty days, and everything in it is deleted then. Until then its booking page stays open and its setup is frozen. Any subscription with the billing provider is cancelled now.';

/** What a close does to a suspended row besides, the closing superseding the pause. */
export const CLOSE_ENDS_SUSPENSION = 'Closing ends the suspension, so the booking page and the admin open again until then.';

const CLOSE_REASON_HELP = 'Emailed to the owners as you write it, with the day the account closes, up to 500 characters.';

const CLOSE_PENDING = 'The close has been sent. This goes away once the server answers.';

/** What a reopen does, said in the confirm before the operator reopens. */
export const REOPEN_WARNING = 'The account stays open: its closing is cancelled, nothing in it is deleted and its setup can be changed again. The owners are emailed.';

const REOPEN_PENDING = 'The reopen has been sent. This closes once the server answers.';

/** A closing row's sentence: since when, and the day it is deleted on. */
export function closingSentence(closing: { at: string; deleted_on: string }): string {
    return `Closing since ${closing.at}, deleted on ${closing.deleted_on}.`;
}

/** A closing row's way back outside the free shape, which the console does not offer. */
export const WAY_BACK_BILLING = 'To keep it, its owner chooses a plan on the Billing screen before then.';

/** The console's writes on a tenant's status, on the console's own host. */
type StatusWrite = 'suspend' | 'resume' | 'close' | 'reopen';

function writePath(slug: string, action: StatusWrite): string {
    return `/tenants/${encodeURIComponent(slug)}/${action}`;
}

/** A free tenant's Change features, on the console's own host. */
function featuresPath(slug: string): string {
    return `/tenants/${encodeURIComponent(slug)}/features`;
}

export const FEATURES_NOTE = 'This account is free of charge, so its features are set here rather than by billing.';

/** The tenant's Open as support, on the console's own host. */
function supportPath(slug: string): string {
    return `/tenants/${encodeURIComponent(slug)}/support`;
}

/** What a support session is, said beside the control before the operator opens one. */
export const SUPPORT_NOTE = 'Opens this business\'s own admin in this browser as support, for sixty minutes, with every screen but Billing. Every change you make is named as support in the booking\'s history. The business is not emailed.';

/** A support session's End, on the console's own host. */
function endPath(session: number): string {
    return `/support/${session}/end`;
}

export const NO_SESSIONS = 'No support session is open at this business.';

/** A user's set-password link, on the console's own host. */
function passwordLinkPath(slug: string, user: number): string {
    return `/tenants/${encodeURIComponent(slug)}/users/${user}/password-link`;
}

function nothing(): void {}

const PEOPLE_HEADS = ['Name', 'Email', 'Role', 'Verified', 'Password'];

const SESSION_HEADS = ['Operator', 'Opened', 'Ends', 'Session'];

/** One of the page's tables under its column heads, scrolling sideways on a narrow screen. */
function HeadedTable({ heads, children }: { heads: string[]; children: ReactNode }) {
    return (
        <TableScroll>
            <table className="admin-table w-full text-sm">
                <thead>
                    <tr className="text-left">
                        {heads.map((head) => (
                            <th key={head} className="px-4 py-2">
                                {head}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </TableScroll>
    );
}

/** One fact of a section: its label, then its value under the class a reader finds it by. */
function Fact({ label, className, children }: { label: string; className: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={className}>{children}</dd>
        </div>
    );
}

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
    return (
        <section className={`admin-card admin-tenant-${id} rounded-xl border`} aria-labelledby={`tenant-${id}-head`}>
            <header className="border-b px-4 py-3">
                <h2 id={`tenant-${id}-head`} className="font-semibold">
                    {title}
                </h2>
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}

const FACTS = 'grid grid-cols-1 gap-3 text-sm desktop:grid-cols-3';

function Account({ tenant }: { tenant: TenantDetail }) {
    return (
        <Section id="account" title="Account">
            <dl className={FACTS}>
                <Fact label="Status" className="admin-tenant__status">
                    {STATUS_WORDS[tenant.status]}
                </Fact>
                <Fact label="Timezone" className="admin-tenant__timezone">
                    {tenant.timezone}
                </Fact>
                <Fact label="Currency" className="admin-tenant__currency">
                    {tenant.currency}
                </Fact>
                <Fact label="Category" className="admin-tenant__category">
                    {tenant.category ?? NONE}
                </Fact>
                <Fact label="Signed up" className="admin-tenant__signed-up">
                    {tenant.signed_up}
                </Fact>
                <Fact label="Terms accepted" className="admin-tenant__terms">
                    {tenant.terms === null ? (
                        TERMS_NOT_RECORDED
                    ) : (
                        <span className="flex flex-col">
                            <span className="admin-tenant__terms-at">{tenant.terms.at}</span>
                            <span className="admin-tenant__terms-version text-muted-foreground">{termsVersion(tenant.terms.version)}</span>
                        </span>
                    )}
                </Fact>
                {tenant.suspension !== null && (
                    <>
                        <Fact label="Suspended" className="admin-tenant__suspended">
                            {tenant.suspension.at}
                        </Fact>
                        <Fact label="Reason" className="admin-tenant__suspension-reason">
                            {tenant.suspension.reason}
                        </Fact>
                    </>
                )}
                {tenant.closing !== null && tenant.status === 'closed' && (
                    <>
                        <Fact label="Closing since" className="admin-tenant__closing">
                            {tenant.closing.at}
                        </Fact>
                        <Fact label="Kept until" className="admin-tenant__retention-ends">
                            {tenant.closing.retention_ends}
                        </Fact>
                    </>
                )}
                {tenant.closed !== null && (
                    <Fact label="Closed" className="admin-tenant__closed">
                        {tenant.closed}
                    </Fact>
                )}
            </dl>
        </Section>
    );
}

/**
 * Change features: a checkbox per choice, started from the server's set, and Save posting them
 * as one list. One save at a time; nothing is shown as saved until the server's answer lands.
 */
function Features({ slug, current, offered }: { slug: string; current: string[]; offered: FeatureChoice[] }) {
    const toasts = useToasts();
    const shell = useShell();
    const { chosen, toggle } = useFeatureChoice(current);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const errorId = 'tenant-features-error';

    const save = () => {
        if (saving) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);
        const started = () => {
            setSaving(true);
            setError(null);
            owned.onStart();
        };
        const finished = () => {
            setSaving(false);
            owned.onFinish();
        };

        router.post(featuresPath(slug), { features: chosenIn(offered, chosen) }, { preserveScroll: true, onStart: started, onFinish: finished, onError: (errors) => setError(errors.features ?? shell.text(NOT_DONE)) });
    };

    return (
        <fieldset className="admin-tenant-features flex flex-col gap-3 border-t pt-4" aria-describedby={error === null ? undefined : errorId}>
            <legend className="admin-tenant-features__legend float-left mb-1 w-full text-sm font-medium">Features</legend>
            <p className="admin-tenant-features__note text-sm text-muted-foreground">{FEATURES_NOTE}</p>
            <FeatureBoxes idPrefix="tenant-feature" className="admin-tenant-features__choice" offered={offered} chosen={chosen} onToggle={toggle} error={error} errorId={errorId} />
            <div className="flex">
                <Button className="admin-tenant-features__save" busy={saving} busyLabel="Saving" onClick={save}>
                    Save features
                </Button>
            </div>
        </fieldset>
    );
}

function Plan({ tenant, names, features }: { tenant: TenantDetail; names: PlanName[]; features: { slug: string; offer: NonNullable<TenantFeatures> } | null }) {
    const subscription = tenant.subscription;

    return (
        <Section id="plan" title="Plan and subscription">
            <div className="flex flex-col gap-4">
                <dl className={FACTS}>
                    <Fact label="Plan" className="admin-tenant__plan">
                        {tenant.plan === null ? NO_PLAN : planWord(tenant.plan, names)}
                    </Fact>
                    <Fact label="Subscription state" className="admin-tenant__state">
                        {STATE_WORDS[tenant.state]}
                    </Fact>
                    <Fact label="Trial end" className="admin-tenant__trial-ends">
                        {tenant.trial_ends ?? NONE}
                    </Fact>
                    <Fact label="Grace end" className="admin-tenant__grace-ends">
                        {tenant.grace_ends ?? NONE}
                    </Fact>
                </dl>
                {subscription === null ? (
                    <p className="admin-tenant__no-subscription text-sm text-muted-foreground">{NO_SUBSCRIPTION}</p>
                ) : (
                    <dl className={FACTS}>
                        <Fact label="Billing customer" className="admin-tenant__customer">
                            {subscription.customer}
                        </Fact>
                        <Fact label="Provider status" className="admin-tenant__provider-status">
                            {subscription.status ?? NOT_YET}
                        </Fact>
                        <Fact label="Period ends" className="admin-tenant__period-end">
                            {subscription.period_end ?? NOT_YET}
                        </Fact>
                    </dl>
                )}
                {features !== null && <Features key={features.offer.current.join(',')} slug={features.slug} current={features.offer.current} offered={features.offer.offered} />}
            </div>
        </Section>
    );
}

function People({ slug, users }: { slug: string; users: Person[] }) {
    const toasts = useToasts();
    const shell = useShell();
    const [sending, setSending] = useState<number | null>(null);

    const send = (person: Person) => {
        if (sending !== null) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(
            passwordLinkPath(slug, person.id),
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setSending(person.id);
                    owned.onStart();
                },
                onFinish: () => {
                    setSending(null);
                    owned.onFinish();
                },
            },
        );
    };

    return (
        <Section id="people" title="People">
            {users.length === 0 ? (
                <p className="admin-tenant-people__none text-sm text-muted-foreground">{NO_USERS}</p>
            ) : (
                <HeadedTable heads={PEOPLE_HEADS}>
                    {users.map((person) => (
                        <tr key={person.id} className="admin-tenant-people__row border-t">
                            <td className="admin-tenant-people__name px-4 py-2">{person.name}</td>
                            <td id={`tenant-person-${person.id}-email`} className="admin-tenant-people__email px-4 py-2">
                                {person.email}
                            </td>
                            <td className="admin-tenant-people__role px-4 py-2">{ROLE_WORDS[person.role]}</td>
                            <td className="admin-tenant-people__verified px-4 py-2">{person.verified ? 'Yes' : 'No'}</td>
                            <td className="px-4 py-2">
                                <Button
                                    className="admin-tenant-people__password-link"
                                    aria-describedby={`tenant-person-${person.id}-email`}
                                    busy={sending === person.id}
                                    busyLabel="Sending"
                                    onClick={() => send(person)}
                                >
                                    Send password link
                                </Button>
                            </td>
                        </tr>
                    ))}
                </HeadedTable>
            )}
        </Section>
    );
}

function ActivitySection({ activity, none }: { activity: Activity | null; none: string }) {
    const shell = useShell();

    return (
        <Section id="activity" title="Activity">
            {activity === null ? (
                <p className="admin-tenant__no-activity text-sm text-muted-foreground">{none}</p>
            ) : (
                <div className="flex flex-col gap-4">
                    <dl className={FACTS}>
                        <Fact label="Services" className="admin-tenant__services">
                            {activity.services}
                        </Fact>
                        <Fact label="Active staff" className="admin-tenant__staff">
                            {activity.staff}
                        </Fact>
                        <Fact label="Last booking made" className="admin-tenant__last">
                            {activity.last ?? NO_LAST_ACTIVITY}
                        </Fact>
                    </dl>
                    <div className="flex flex-col gap-2">
                        <h3 className="text-sm font-medium">Bookings by status</h3>
                        <dl className="admin-tenant-bookings grid grid-cols-2 gap-x-6 gap-y-1 text-sm desktop:grid-cols-3">
                            {activity.bookings.map((group) => (
                                <div key={group.status} className="flex justify-between gap-2">
                                    <dt className="text-muted-foreground">{statusLabel(group.status, shell)}</dt>
                                    <dd className="tabular-nums" data-status={group.status}>
                                        {group.count}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            )}
        </Section>
    );
}

/** What a reason prompt says and is called, Suspend's or Close's. */
type ReasonWords = { name: string; field: string; title: string; confirmLabel: string; busyLabel: string; pending: string; help: string; warnings: string[] };

/**
 * A confirm holding the reason the owners are mailed, Suspend's or Close's: the warnings, the
 * reason field and the two buttons, on the admin's one Confirm. While the post is in flight no
 * path dismisses it, Keep it says why it is disabled, and the confirm shows its busy state.
 */
function ReasonPrompt({ words, open, reason, error, busy, onReason, onConfirm, onDismiss }: { words: ReasonWords; open: boolean; reason: string; error: string | null; busy: boolean; onReason: (value: string) => void; onConfirm: () => void; onDismiss: () => void }) {
    return (
        <Confirm open={open} title={words.title} name={words.name} confirmLabel={words.confirmLabel} busyLabel={words.busyLabel} pending={words.pending} busy={busy} onConfirm={onConfirm} onDismiss={onDismiss}>
            <div className="flex flex-col gap-4">
                {words.warnings.map((warning) => (
                    <p key={warning} className={`${words.name}__warning text-sm`}>
                        {warning}
                    </p>
                ))}
                <Field id={words.field} label="Reason" help={words.help} error={error}>
                    {(control) => <Textarea {...control} className={`${words.name}__reason`} rows={4} value={reason} onChange={(event) => onReason(event.target.value)} />}
                </Field>
            </div>
        </Confirm>
    );
}

function suspendWords(name: string): ReasonWords {
    return { name: 'admin-suspend-prompt', field: 'suspend-reason', title: `Suspend ${name}`, confirmLabel: 'Suspend', busyLabel: 'Suspending', pending: SUSPEND_PENDING, help: REASON_HELP, warnings: [SUSPEND_WARNING] };
}

function closeWords(tenant: TenantDetail): ReasonWords {
    return {
        name: 'admin-close-prompt',
        field: 'close-reason',
        title: `Close ${tenant.name}`,
        confirmLabel: 'Close the account',
        busyLabel: 'Closing',
        pending: CLOSE_PENDING,
        help: CLOSE_REASON_HELP,
        warnings: tenant.status === 'suspended' ? [CLOSE_WARNING, CLOSE_ENDS_SUSPENSION] : [CLOSE_WARNING],
    };
}

/** Whether the row offers Reopen: closing, in the free shape the server derived as free. */
function reopens(tenant: TenantDetail): boolean {
    return tenant.status === 'closing' && tenant.plan === 'free';
}

/**
 * The controls the tenant's status offers: Suspend and Close on an active row, Resume and Close on
 * a suspended one, Reopen on a closing row in the free shape, and nothing on a provisioning row or
 * any other closing row. Each press posts once and shows its busy state until the server's answer
 * has landed.
 */
function Controls({ tenant, slug }: { tenant: TenantDetail; slug: string }) {
    const toasts = useToasts();
    const shell = useShell();
    const [prompting, setPrompting] = useState<StatusWrite | null>(null);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState<StatusWrite | null>(null);

    const post = (action: StatusWrite, body: Record<string, string>, onSuccess: () => void, onError: (errors: Record<string, string>) => void) => {
        if (busy !== null) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(writePath(slug, action), body, {
            preserveScroll: true,
            onStart: () => {
                setBusy(action);
                owned.onStart();
            },
            onFinish: () => {
                setBusy(null);
                owned.onFinish();
            },
            onSuccess,
            onError,
        });
    };

    const ask = (action: 'suspend' | 'close') => {
        setReason('');
        setError(null);
        setPrompting(action);
    };

    const dismiss = () => setPrompting(null);

    const withReason = (action: 'suspend' | 'close') => {
        setError(null);
        post(action, { reason }, dismiss, (errors) => setError(errors.reason ?? shell.text(NOT_DONE)));
    };

    const opened = tenant.status === 'active' || tenant.status === 'suspended';

    if (!opened && !reopens(tenant)) {
        return null;
    }

    return (
        <div className="admin-tenant-controls flex flex-wrap gap-2">
            {tenant.status === 'active' && (
                <Button destructive className="admin-tenant-suspend" onClick={() => ask('suspend')}>
                    Suspend
                </Button>
            )}
            {tenant.status === 'suspended' && (
                <Button className="admin-tenant-resume" onClick={() => setPrompting('resume')}>
                    Resume
                </Button>
            )}
            {opened && (
                <Button destructive className="admin-tenant-close" onClick={() => ask('close')}>
                    Close
                </Button>
            )}
            {reopens(tenant) && (
                <Button className="admin-tenant-reopen" onClick={() => setPrompting('reopen')}>
                    Reopen
                </Button>
            )}
            <ReasonPrompt words={suspendWords(tenant.name)} open={prompting === 'suspend'} reason={reason} error={error} busy={busy === 'suspend'} onReason={setReason} onConfirm={() => withReason('suspend')} onDismiss={dismiss} />
            <ReasonPrompt words={closeWords(tenant)} open={prompting === 'close'} reason={reason} error={error} busy={busy === 'close'} onReason={setReason} onConfirm={() => withReason('close')} onDismiss={dismiss} />
            <Confirm
                open={prompting === 'resume'}
                title={`Resume ${tenant.name}`}
                name="admin-resume-prompt"
                confirmLabel="Resume"
                busyLabel="Resuming"
                pending={RESUME_PENDING}
                busy={busy === 'resume'}
                destructive={false}
                onConfirm={() => post('resume', {}, dismiss, nothing)}
                onDismiss={dismiss}
            >
                <p className="admin-resume-prompt__warning text-sm">{RESUME_WARNING}</p>
            </Confirm>
            <Confirm
                open={prompting === 'reopen'}
                title={`Reopen ${tenant.name}`}
                name="admin-reopen-prompt"
                confirmLabel="Reopen"
                busyLabel="Reopening"
                pending={REOPEN_PENDING}
                busy={busy === 'reopen'}
                destructive={false}
                onConfirm={() => post('reopen', {}, dismiss, nothing)}
                onDismiss={dismiss}
            >
                <p className="admin-reopen-prompt__warning text-sm">{REOPEN_WARNING}</p>
            </Confirm>
        </div>
    );
}

/**
 * A closing row's sentence, since when and the day it is deleted on, and outside the free shape
 * its way back, the owner's Billing screen, which the console does not offer.
 */
function Closing({ tenant }: { tenant: TenantDetail }) {
    if (tenant.status !== 'closing' || tenant.closing === null) {
        return null;
    }

    return (
        <div className="admin-tenant-closing flex flex-col gap-1 text-sm">
            <p className="admin-tenant-closing__sentence font-medium">{closingSentence(tenant.closing)}</p>
            {!reopens(tenant) && <p className="admin-tenant-closing__way-back text-muted-foreground">{WAY_BACK_BILLING}</p>}
        </div>
    );
}

/**
 * Open as support on an active or a closing row: one press at a time, busy until the server's
 * answer lands, which is the tenant host's link followed as a full-page visit.
 */
function Support({ tenant, slug }: { tenant: TenantDetail; slug: string }) {
    const toasts = useToasts();
    const shell = useShell();
    const [opening, setOpening] = useState(false);
    const noteId = 'tenant-support-note';

    const open = () => {
        if (opening) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(
            supportPath(slug),
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setOpening(true);
                    owned.onStart();
                },
                onFinish: () => {
                    setOpening(false);
                    owned.onFinish();
                },
            },
        );
    };

    if (tenant.status !== 'active' && tenant.status !== 'closing') {
        return null;
    }

    return (
        <div className="admin-tenant-support flex flex-col gap-2 desktop:flex-row desktop:items-center desktop:gap-4">
            <Button className="admin-tenant-support__open self-start" aria-describedby={noteId} busy={opening} busyLabel="Opening" onClick={open}>
                Open as support
            </Button>
            <p id={noteId} className="admin-tenant-support__note text-sm text-muted-foreground">
                {SUPPORT_NOTE}
            </p>
        </div>
    );
}

/**
 * The open support sessions with End beside each: one end at a time, busy on its own row until
 * the server's answer lands, which is this page again with the list read afresh.
 */
function SupportSessions({ sessions }: { sessions: OpenSupportSession[] }) {
    const toasts = useToasts();
    const shell = useShell();
    const [ending, setEnding] = useState<number | null>(null);

    const end = (session: OpenSupportSession) => {
        if (ending !== null) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? shell.text(NOT_DONE)), shell);

        router.post(
            endPath(session.id),
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setEnding(session.id);
                    owned.onStart();
                },
                onFinish: () => {
                    setEnding(null);
                    owned.onFinish();
                },
            },
        );
    };

    return (
        <Section id="sessions" title="Support sessions">
            {sessions.length === 0 ? (
                <p className="admin-tenant-sessions__none text-sm text-muted-foreground">{NO_SESSIONS}</p>
            ) : (
                <HeadedTable heads={SESSION_HEADS}>
                    {sessions.map((session) => (
                        <tr key={session.id} className="admin-tenant-sessions__row border-t">
                            <td id={`tenant-session-${session.id}-operator`} className="admin-tenant-sessions__operator px-4 py-2">
                                {session.operator}
                            </td>
                            <td className="admin-tenant-sessions__opened px-4 py-2">{session.opened}</td>
                            <td className="admin-tenant-sessions__ends px-4 py-2">{session.ends}</td>
                            <td className="px-4 py-2">
                                <Button
                                    destructive
                                    className="admin-tenant-sessions__end"
                                    aria-describedby={`tenant-session-${session.id}-operator`}
                                    busy={ending === session.id}
                                    busyLabel="Ending"
                                    onClick={() => end(session)}
                                >
                                    End
                                </Button>
                            </td>
                        </tr>
                    ))}
                </HeadedTable>
            )}
        </Section>
    );
}

/** The links out of the page: the booking page and the admin's sign-in in a new tab, and the Audit screen. */
function Links({ links }: { links: TenantLinks }) {
    return (
        <nav className="admin-tenant-links flex flex-wrap gap-x-4 gap-y-2 text-sm" aria-label="Links">
            <a className="admin-tenant-links__hosted underline underline-offset-4" href={links.hosted_page} target="_blank" rel="noreferrer">
                {HOSTED_PAGE}
            </a>
            <a className="admin-tenant-links__sign-in underline underline-offset-4" href={links.sign_in} target="_blank" rel="noreferrer">
                {SIGN_IN}
            </a>
            <Link className="admin-tenant-links__audit underline underline-offset-4" href={links.audit}>
                {AUDIT}
            </Link>
        </nav>
    );
}

export default function Tenant() {
    const { props } = usePage<Props>();
    const tenant = props.tenant;
    const slug = tenant.slug;

    return (
        <>
            <Head title={tenant.name} />
            <div className="admin-tenant-screen flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <Link href="/" className="admin-tenant-back text-sm underline-offset-4 hover:underline">
                        {ALL_TENANTS}
                    </Link>
                    <h1 className="admin-tenant__name text-2xl font-semibold">{tenant.name}</h1>
                    <p className="admin-tenant__slug text-muted-foreground">{slug ?? SLUG_RELEASED}</p>
                </div>
                {slug !== null && (
                    <>
                        {props.links !== null && <Links links={props.links} />}
                        <Closing tenant={tenant} />
                        <Controls tenant={tenant} slug={slug} />
                        <Support tenant={tenant} slug={slug} />
                        <SupportSessions sessions={props.support_sessions} />
                    </>
                )}
                <Account tenant={tenant} />
                <Plan tenant={tenant} names={props.plan_names} features={slug === null || props.features === null ? null : { slug, offer: props.features }} />
                {slug === null ? (
                    <Section id="people" title="People">
                        <p className="admin-tenant-people__none text-sm text-muted-foreground">{CLOSED_USERS}</p>
                    </Section>
                ) : (
                    <People slug={slug} users={tenant.users} />
                )}
                <ActivitySection activity={tenant.activity} none={slug === null ? CLOSED_ACTIVITY : NO_ACTIVITY} />
            </div>
        </>
    );
}
