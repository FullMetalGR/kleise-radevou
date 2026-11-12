import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { CheckField } from '@/components/ui/check-field';
import { Field } from '@/components/ui/field';
import { TextInput, Textarea } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { Select } from '@/components/ui/select';
import { StatusChip } from '@/components/ui/status-chip';
import { formatTimeOfDay, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { canSave, type Form, HORIZON_MAX_DAYS, type Member, optionsIn, PAYMENT_MODES, STATUSES, statusWord, TIMEOUTS, weekdayLabel } from './model';
import { type ServicesKey, useServices } from './words';

/*
 * The service detail page: a breadcrumb, a title row with the name, the status chip and, on a
 * persisted row, the Copy booking link button, the named sections in order and a sticky save
 * bar. Basics, Timing, Staff who perform this (only where the staff axis is shown), Booking
 * rules, Payment, and the Danger zone on a persisted row only.
 *
 * The only client gate is the name: Save is disabled while it is blank after trimming.
 * Everything else is the server's to refuse, and its sentence reaches the page as an error toast
 * with every edit still here. Nothing is optimistic: the form is what was typed until the
 * server's answer replaces the row it was built from.
 *
 * Every word is a key of the services section the screen provides; the granularity and the row's
 * currency inside a line are placeholders filled from the code.
 */

/**
 * The members the checklist shows, and whether its ticks can change: only with the staff feature.
 */
/**
 * The roster the checklist ticks; shifts is whether the viewer may open Shifts, which holds
 * manageSettings alone.
 */
export type Roster = { loading: boolean; members: readonly Member[]; editable: boolean; shifts: boolean };

/** Where a member's hours are kept, the Shifts screen. */
const SHIFTS_HREF = '/admin/shifts';

const READ_ONLY = 'staff.read_only' satisfies ServicesKey;

export const CATEGORY_HELP = 'form.category_help' satisfies ServicesKey;

export const ONLINE_HELP = 'form.online_help' satisfies ServicesKey;

type Props = {
    form: Form;
    onChange: (form: Form) => void;
    creating: boolean;
    categories: readonly string[];
    title: string;
    status: string | null;
    currency: string;
    granularity: number;
    clock: TenantClock;
    staff: Roster | null;
    staged: readonly number[];
    onStage: (staged: number[]) => void;
    busy: boolean;
    onSave: () => void;
    onBack: () => void;
    listHref: string;
    danger: ReactNode;
    /** The Copy booking link control of a persisted row, beside the title; null while creating. */
    copyLink: ReactNode;
};

/** The fields a person writes a number into, held as text until Save folds them. */
type NumberKey = { [K in keyof Form]: Form[K] extends string ? K : never }[keyof Form];

const GAP_HELP = 'form.gap_help' satisfies ServicesKey;

function Section({ id, title, meta, children }: { id: string; title: string; meta?: string; children: ReactNode }) {
    return (
        <section className={`admin-section admin-section--${id} rounded-xl border p-4`} aria-labelledby={`section-${id}`}>
            <header className="mb-3 flex items-baseline justify-between">
                <h2 id={`section-${id}`} className="font-semibold">
                    {title}
                </h2>
                {meta !== undefined && <p className="admin-section__meta text-sm text-muted-foreground">{meta}</p>}
            </header>
            <div className="grid gap-4 sm:grid-cols-2">{children}</div>
        </section>
    );
}

export function ServiceForm({ form, onChange, creating, categories, title, status, currency, granularity, clock, staff, staged, onStage, busy, onSave, onBack, listHref, danger, copyLink }: Props) {
    const words = useServices();
    const set = <K extends keyof Form>(key: K) => (value: Form[K]) => onChange({ ...form, [key]: value });
    const number = (id: NumberKey, label: ServicesKey, help: string, step?: number, max?: number) => (
        <Field id={`service-${id}`} label={words.text(label)} help={help}>
            {(control) => <TextInput {...control} type="number" min={0} max={max} step={step} className={`admin-service-${id}`} value={form[id]} onChange={(event) => onChange({ ...form, [id]: event.target.value })} />}
        </Field>
    );

    return (
        <form
            className="admin-service-form flex flex-col gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSave();
            }}
        >
            <nav className="admin-breadcrumb text-sm text-muted-foreground" aria-label={words.text('form.breadcrumb')}>
                <a
                    href={listHref}
                    className="admin-breadcrumb__list underline"
                    onClick={(event) => {
                        event.preventDefault();
                        onBack();
                    }}
                >
                    {words.text('form.list')}
                </a>
                <span aria-hidden="true"> / </span>
                <span className="admin-breadcrumb__current">{title}</span>
            </nav>
            <div className="admin-service-title flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-semibold">{title}</h1>
                {status !== null && <StatusChip status={status} label={statusWord(status, words)} />}
                {copyLink}
            </div>

            <Section id="basics" title={words.text('form.basics')}>
                <Field id="service-name" label={words.text('form.name')}>
                    {(control) => <TextInput {...control} value={form.name} onChange={(event) => set('name')(event.target.value)} />}
                </Field>
                {!creating && (
                    <Field id="service-status" label={words.text('form.status')}>
                        {(control) => <Select {...control} options={optionsIn(STATUSES, words)} value={form.status} onChange={set('status')} />}
                    </Field>
                )}
                <Field id="service-description" label={words.text('form.description')} help={words.text('form.description_help')} className="sm:col-span-2">
                    {(control) => <Textarea {...control} rows={3} value={form.description} onChange={(event) => set('description')(event.target.value)} />}
                </Field>
                <Field id="service-category" label={words.text('form.category')} help={words.text(CATEGORY_HELP)}>
                    {(control) => <TextInput {...control} list="service-category-options" maxLength={60} value={form.category} onChange={(event) => set('category')(event.target.value)} />}
                </Field>
                <datalist id="service-category-options" className="admin-service-categories">
                    {categories.map((category) => (
                        <option key={category} value={category} />
                    ))}
                </datalist>
                <CheckField id="service-online" label={words.text('form.online')} help={words.text(ONLINE_HELP)} checked={form.online} onChange={set('online')} />
            </Section>

            <Section id="timing" title={words.text('form.timing')}>
                {number('duration_min', 'form.duration', words.text('form.duration_help', { n: String(granularity) }), granularity)}
                {number('processing_gap_min', 'form.gap', words.text(GAP_HELP))}
                {number('buffer_before_min', 'form.buffer_before', words.text('form.buffer_before_help'))}
                {number('buffer_after_min', 'form.buffer_after', words.text('form.buffer_after_help'))}
            </Section>

            {staff !== null && <StaffChecklist staff={staff} staged={staged} onStage={onStage} clock={clock} />}

            <Section id="rules" title={words.text('form.rules')}>
                {number('lead_time_min', 'form.lead_time', words.text('form.lead_time_help'))}
                {number('horizon_days', 'form.horizon', words.text('form.horizon_help'), undefined, HORIZON_MAX_DAYS)}
                {number('cancel_window_hours', 'form.cancel_window', words.text('form.cancel_window_help'))}
                {number('reschedule_window_hours', 'form.reschedule_window', words.text('form.reschedule_window_help'))}
                <div className="admin-approval flex items-center gap-2 sm:col-span-2">
                    <input type="checkbox" id="service-requires-approval" className="admin-approval__tick" checked={form.requires_approval} onChange={(event) => set('requires_approval')(event.target.checked)} />
                    <label htmlFor="service-requires-approval">{words.text('form.approval')}</label>
                </div>
                {form.requires_approval && (
                    <>
                        {number('approval_hold_hours', 'form.hold', words.text('form.hold_help'))}
                        <Field id="service-timeout" label={words.text('form.timeout')}>
                            {(control) => <Select {...control} options={optionsIn(TIMEOUTS, words)} value={form.on_approval_timeout} onChange={set('on_approval_timeout')} />}
                        </Field>
                    </>
                )}
            </Section>

            <Section id="payment" title={words.text('form.payment')}>
                <Field id="service-price" label={words.text('form.price', { currency })} help={words.text('form.price_help')}>
                    {(control) => <TextInput {...control} inputMode="decimal" className="font-mono" value={form.price} onChange={(event) => set('price')(event.target.value)} />}
                </Field>
                <Field id="service-payment-mode" label={words.text('form.payment_mode')}>
                    {(control) => <Select {...control} options={optionsIn(PAYMENT_MODES, words)} value={form.payment_mode} onChange={set('payment_mode')} />}
                </Field>
            </Section>

            {!creating && danger}

            <div className="admin-save-bar sticky bottom-0 flex items-center gap-3 border-t bg-background py-3">
                <Button type="submit" variant="primary" className="admin-service-save" disabled={!canSave(form)} busy={busy} busyLabel={words.text('form.saving')}>
                    {words.text('form.save')}
                </Button>
                {!canSave(form) && (
                    <p className="admin-save-bar__reason text-sm text-muted-foreground" role="status">
                        {words.text('form.needs_name')}
                    </p>
                )}
            </div>
        </form>
    );
}

type ChecklistProps = { staff: Roster; staged: readonly number[]; onStage: (staged: number[]) => void; clock: TenantClock };

/**
 * Staff who perform this, 3.3, under the staff axis alone: one checkbox per member, inactive
 * ones suffixed, that member's weekly windows printed read-only beside the row in the tenant's
 * clock, and a link to the Shifts screen for a viewer who may open it (manageSettings), none for
 * any other. The meta counts the staged ticks and is omitted entirely while the roster is
 * loading. Without the staff feature the rows half of 6.1 still shows who performs the service,
 * but a tick is a staff write the server refuses, so every tick is disabled as linked, one line
 * says why, and the link reads as a way to see the hours.
 */
function StaffChecklist({ staff, staged, onStage, clock }: ChecklistProps) {
    const shell = useShell();
    const words = useServices();
    const windows = (member: Member): string =>
        member.windows.length === 0
            ? words.text('staff.no_hours')
            : member.windows.map((window) => words.text('staff.window', { weekday: weekdayLabel(window.weekday, shell.language), time: formatTimeOfDay(window.start, clock), end: formatTimeOfDay(window.end, clock) })).join(', ');

    return (
        <Section id="staff" title={words.text('staff.title')} meta={staff.loading ? undefined : words.choice('staff.ticked', staff.members.length, { ticked: String(staged.length) })}>
            <ul className="admin-staff-checklist flex flex-col gap-2 sm:col-span-2">
                {staff.members.map((member) => (
                    <li key={member.id} className="admin-staff-checklist__row flex flex-wrap items-baseline gap-3">
                        <input
                            type="checkbox"
                            id={`service-staff-${member.id}`}
                            className="admin-staff-checklist__tick"
                            checked={staged.includes(member.id)}
                            disabled={!staff.editable}
                            onChange={(event) => {
                                if (staff.editable) {
                                    onStage(event.target.checked ? [...staged, member.id] : staged.filter((id) => id !== member.id));
                                }
                            }}
                        />
                        <label htmlFor={`service-staff-${member.id}`} className="admin-staff-checklist__name">
                            {member.active ? member.name : words.text('staff.inactive', { staff: member.name })}
                        </label>
                        <span className="admin-staff-checklist__windows text-xs text-muted-foreground">
                            {windows(member)}
                        </span>
                    </li>
                ))}
            </ul>
            {!staff.editable && <p className="admin-staff-checklist__readonly text-sm text-muted-foreground sm:col-span-2">{words.text(READ_ONLY)}</p>}
            {staff.shifts && (
                <p className="text-sm sm:col-span-2">
                    <a href={SHIFTS_HREF} className="admin-staff-checklist__link underline">
                        {words.text(staff.editable ? 'staff.edit_hours' : 'staff.see_hours')}
                    </a>
                </p>
            )}
        </Section>
    );
}

type DangerProps = {
    conflict: string | null;
    deleting: boolean;
    deactivating: boolean;
    onDelete: () => void;
    onDeactivate: () => void;
};

/**
 * The Danger zone, 3.3 and 9.2: Delete, and beside it, only once a delete earned the referenced
 * conflict, the inline warning with the remedy. Deactivate it instead patches the status and
 * keeps the page.
 */
export function DangerZone({ conflict, deleting, deactivating, onDelete, onDeactivate }: DangerProps) {
    const words = useServices();

    return (
        <section className="admin-section admin-section--danger rounded-xl border border-destructive/50 p-4" aria-labelledby="section-danger">
            <h2 id="section-danger" className="mb-3 font-semibold">
                {words.text('danger.title')}
            </h2>
            <div className="flex flex-col gap-3">
                <Button destructive className="admin-service-delete self-start" busy={deleting} busyLabel={words.text('danger.deleting')} onClick={onDelete}>
                    {words.text('danger.delete')}
                </Button>
                {conflict !== null && (
                    <Notice kind="warning" className="admin-service-conflict">
                        {conflict}{' '}
                        <Button variant="link" className="admin-service-deactivate" busy={deactivating} busyLabel={words.text('danger.deactivating')} onClick={onDeactivate}>
                            {words.text('danger.deactivate')}
                        </Button>
                    </Notice>
                )}
            </div>
        </section>
    );
}
