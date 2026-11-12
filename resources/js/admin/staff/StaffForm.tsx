import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { CheckField } from '@/components/ui/check-field';
import { Combobox } from '@/components/ui/combobox';
import { Field, type FieldControl } from '@/components/ui/field';
import { Textarea, TextInput } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { Select } from '@/components/ui/select';
import { StatusChip } from '@/components/ui/status-chip';
import type { TenantClock } from '@/lib/format';
import { BIO_LIMIT, canSave, type CatalogService, characters, type Form, patternLine, type StaffPattern, STATUSES, statusWord, type TenantUser, TITLE_LIMIT, userOptions } from './model';
import { type StaffKey, type StaffWords, useStaff } from './words';

/** The Shifts screen. */
export const SHIFTS_PATH = '/admin/shifts';

/*
 * The staff detail page: a breadcrumb, a title row with the name and the status chip, then Basics
 * (Name, Email, Linked user as the combobox over the tenant's users, Status, Offered online, Title,
 * Bio with its count, and the Photo where the caller hands one), Services performed with inactive
 * services marked rather than hidden, Weekly availability as one read-only line of the member's
 * repeating pattern with the link to Shifts, where it is edited, then, on a persisted row alone,
 * Exceptions for this staff member and the Danger zone, outside the member's form (FORM_ID), and a
 * sticky save bar. The only client gate is the name; while another write of the page is in flight
 * Save is held with that write's sentence beside it.
 *
 * The photo is the caller's control, its own write on its own two routes, offered on a persisted
 * member who can have a face: a new member has no id for the route and the implicit member stands
 * for the business, so for either the caller hands none and no Photo field renders. The implicit
 * member has no Title and no Bio either, which show nowhere for that row and which the server
 * ignores for it (P9F-L35-b); its Offered online help says instead what unticking does to the
 * business's own row (P9F-20b).
 *
 * Every word is a key of the staff section, read through the screen's words; the constants below
 * hold keys and keep their names.
 */

export const ONLINE_HELP = 'form.online_help' satisfies StaffKey;

/**
 * The implicit member's help: customers never pick that row by name, but its flag is honoured on
 * the public road, so unticking it leaves a service it alone performs with no time to offer.
 */
export const IMPLICIT_ONLINE_HELP = 'form.implicit_online_help' satisfies StaffKey;

export const TITLE_HELP = 'form.title_help' satisfies StaffKey;

export const BIO_HELP = 'form.bio_help' satisfies StaffKey;

/** Read as a choice by the largest file's megabytes, :n the figure in words the page carries. */
export const PHOTO_HELP = 'form.photo_help' satisfies StaffKey;

/** The largest photo the server takes, StaffPhotoRequest::MAX_KILOBYTES, as the page carries it. */
export type PhotoLimit = { megabytes: number; spelled: string };

/** The bio's count line under the field, in characters as the server counts them. */
export function bioCount(count: number, words: StaffWords): string {
    return words.choice('form.bio_count', count, { max: String(BIO_LIMIT) });
}

const NAME_NEEDED = 'form.needs_name' satisfies StaffKey;

/**
 * The member's form, which holds Basics, Services performed and Weekly availability alone: the
 * closures card and the Danger zone below it carry forms and buttons of their own, and a form
 * inside a form is not one a browser submits, so Save names the form from the save bar beneath
 * them.
 */
const FORM_ID = 'staff-form';

/** The bio's count line, which the textarea names after its help. */
const BIO_COUNT = 'staff-bio-count';

type Props = {
    form: Form;
    onChange: (form: Form) => void;
    creating: boolean;
    /** Whether the member is the implicit one, which renders no Title and no Bio. */
    implicit: boolean;
    title: string;
    status: string | null;
    users: readonly TenantUser[];
    services: readonly CatalogService[];
    clock: TenantClock;
    /** The member's pattern as stored, null for a new member or one with none. */
    pattern: StaffPattern | null;
    busy: boolean;
    /** The sentence for another write of the page in flight, which holds Save, or null. */
    wait: string | null;
    /** The photo control in the field shell, or null where the member can have none. */
    photo: ((control: FieldControl) => ReactNode) | null;
    /** The largest photo, which the photo's help names. */
    photoLimit: PhotoLimit;
    onSave: () => void;
    onBack: () => void;
    listHref: string;
    exceptions: ReactNode;
    danger: ReactNode;
    /** The Copy booking link control of a persisted, named member, beside the title; null otherwise. */
    copyLink: ReactNode;
};

/**
 * Weekly availability's one line: the member's pattern as a read-only line, one whole sentence
 * per case, and beside it the link to Shifts, where the pattern and each date are edited, its own
 * words rather than a phrase spliced onto the line.
 */
export function PatternSummary({ pattern, clock }: { pattern: StaffPattern | null; clock: TenantClock }) {
    const words = useStaff();

    return (
        <p className="admin-staff-pattern text-sm">
            <span className="admin-staff-pattern__line">{patternLine(pattern, clock, words)}</span>{' '}
            <Link href={SHIFTS_PATH} className="admin-staff-shifts-link underline">
                {words.text('pattern.link')}
            </Link>
        </p>
    );
}

export function Section({ id, title, children, wide = false }: { id: string; title: string; children: ReactNode; wide?: boolean }) {
    return (
        <section className={`admin-section admin-section--${id} rounded-xl border p-4`} aria-labelledby={`section-${id}`}>
            <h2 id={`section-${id}`} className="mb-3 font-semibold">
                {title}
            </h2>
            <div className={wide ? 'grid gap-4' : 'grid gap-4 sm:grid-cols-2'}>{children}</div>
        </section>
    );
}

export function StaffForm({ form, onChange, creating, implicit, title, status, users, services, clock, pattern, busy, wait, photo, photoLimit, onSave, onBack, listHref, exceptions, danger, copyLink }: Props) {
    const words = useStaff();
    const set = <K extends keyof Form>(key: K) => (value: Form[K]) => onChange({ ...form, [key]: value });
    const tick = (id: number, ticked: boolean) => set('service_ids')(ticked ? [...form.service_ids, id] : form.service_ids.filter((linked) => linked !== id));

    return (
        <div className="admin-staff-page flex flex-col gap-4">
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
            <div className="admin-staff-title flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-semibold">{title}</h1>
                {status !== null && <StatusChip status={status} label={statusWord(status, words)} />}
                {copyLink}
            </div>

            <form
                id={FORM_ID}
                className="admin-staff-form flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    onSave();
                }}
            >
                <Basics form={form} set={set} users={users} implicit={implicit} photo={photo} photoLimit={photoLimit} />

                <Section id="services" title={words.text('form.services')} wide>
                    {services.length === 0 ? (
                        <p className="admin-staff-services__none text-sm text-muted-foreground">{words.text('form.no_services')}</p>
                    ) : (
                        <ul className="admin-staff-services flex flex-col gap-2">
                            {services.map((service) => (
                                <li key={service.id} className="admin-staff-services__row flex items-center gap-3">
                                    <input type="checkbox" id={`staff-service-${service.id}`} className="admin-staff-services__tick" checked={form.service_ids.includes(service.id)} onChange={(event) => tick(service.id, event.target.checked)} />
                                    <label htmlFor={`staff-service-${service.id}`} className="admin-staff-services__name">
                                        {service.status === 'inactive' ? words.text('form.inactive_service', { service: service.name }) : service.name}
                                    </label>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section id="weekly" title={words.text('form.weekly')} wide>
                    <PatternSummary pattern={pattern} clock={clock} />
                </Section>
            </form>

            {!creating && exceptions}
            {!creating && danger}

            <div className="admin-save-bar sticky bottom-0 flex items-center gap-3 border-t bg-background py-3">
                <Button type="submit" form={FORM_ID} variant="primary" className="admin-staff-save" disabled={!canSave(form) || wait !== null} busy={busy} busyLabel={words.text('form.saving')}>
                    {words.text('form.save')}
                </Button>
                {(wait !== null || !canSave(form)) && (
                    <p className="admin-save-bar__reason text-sm text-muted-foreground" role="status">
                        {wait ?? words.text(NAME_NEEDED)}
                    </p>
                )}
            </div>
        </div>
    );
}

type BasicsProps = {
    form: Form;
    set: <K extends keyof Form>(key: K) => (value: Form[K]) => void;
    users: readonly TenantUser[];
    implicit: boolean;
    photo: ((control: FieldControl) => ReactNode) | null;
    photoLimit: PhotoLimit;
};

/**
 * Basics: the row's own fields, then, on a member other than the implicit one, the Title and the
 * Bio with its count, and the Photo where one is handed in.
 */
function Basics({ form, set, users, implicit, photo, photoLimit }: BasicsProps) {
    const words = useStaff();

    return (
        <Section id="basics" title={words.text('form.basics')}>
            <Field id="staff-name" label={words.text('form.name')}>
                {(control) => <TextInput {...control} value={form.name} onChange={(event) => set('name')(event.target.value)} />}
            </Field>
            <Field id="staff-email" label={words.text('form.email')} help={words.text('form.email_help')}>
                {(control) => <TextInput {...control} type="email" value={form.email} onChange={(event) => set('email')(event.target.value)} />}
            </Field>
            <Field id="staff-user" label={words.text('form.user')} help={words.text('form.user_help')}>
                {(control) => <Combobox {...control} className="admin-staff-user" options={userOptions(users, words)} value={form.user_id} onChange={set('user_id')} />}
            </Field>
            <Field id="staff-status" label={words.text('form.status')}>
                {(control) => <Select {...control} options={STATUSES.map((status) => ({ value: status.value, label: words.text(status.label) }))} value={form.status} onChange={set('status')} />}
            </Field>
            <CheckField id="staff-online" label={words.text('form.online')} help={words.text(implicit ? IMPLICIT_ONLINE_HELP : ONLINE_HELP)} checked={form.online} onChange={set('online')} />
            {!implicit && (
                <>
                    <Field id="staff-title" label={words.text('form.title')} help={words.text(TITLE_HELP)}>
                        {(control) => <TextInput {...control} maxLength={TITLE_LIMIT} value={form.title} onChange={(event) => set('title')(event.target.value)} />}
                    </Field>
                    <Field id="staff-bio" label={words.text('form.bio')} help={words.text(BIO_HELP)} className="sm:col-span-2">
                        {(control) => (
                            <>
                                <Textarea {...control} aria-describedby={[control['aria-describedby'], BIO_COUNT].filter((id) => id !== undefined).join(' ')} rows={4} value={form.bio} onChange={(event) => set('bio')(event.target.value)} />
                                <p id={BIO_COUNT} className="admin-staff-bio__count text-sm text-muted-foreground">
                                    {bioCount(characters(form.bio), words)}
                                </p>
                            </>
                        )}
                    </Field>
                </>
            )}
            {photo !== null && (
                <Field id="staff-photo" label={words.text('form.photo')} help={words.choice(PHOTO_HELP, photoLimit.megabytes, { n: photoLimit.spelled })} className="sm:col-span-2">
                    {photo}
                </Field>
            )}
        </Section>
    );
}

type DangerProps = {
    implicit: boolean;
    conflict: string | null;
    deleting: boolean;
    deactivating: boolean;
    onDelete: () => void;
    onDeactivate: () => void;
};

/** The implicit member's zone reads the server's own refusal of that delete, the same key. */
const IMPLICIT_KEPT = 'refusals.implicit' satisfies StaffKey;

/**
 * The Danger zone: Delete, and beside it, only once a delete earned the referenced conflict,
 * the inline warning with Deactivate them instead, which patches the status and then leaves for
 * the list. The implicit member, the one row that stands for the business, is never deleted:
 * its zone carries the sentence and no Delete, and the server refuses the request whatever the
 * page offers.
 */
export function StaffDangerZone({ implicit, conflict, deleting, deactivating, onDelete, onDeactivate }: DangerProps) {
    const words = useStaff();

    if (implicit) {
        return (
            <section className="admin-section admin-section--danger rounded-xl border border-destructive/50 p-4" aria-labelledby="section-danger">
                <h2 id="section-danger" className="mb-3 font-semibold">
                    {words.text('danger.title')}
                </h2>
                <p className="admin-staff-implicit text-sm text-muted-foreground">{words.text(IMPLICIT_KEPT)}</p>
            </section>
        );
    }

    return (
        <section className="admin-section admin-section--danger rounded-xl border border-destructive/50 p-4" aria-labelledby="section-danger">
            <h2 id="section-danger" className="mb-3 font-semibold">
                {words.text('danger.title')}
            </h2>
            <div className="flex flex-col gap-3">
                <Button destructive className="admin-staff-delete self-start" busy={deleting} busyLabel={words.text('danger.deleting')} onClick={onDelete}>
                    {words.text('danger.delete')}
                </Button>
                {conflict !== null && (
                    <Notice kind="warning" className="admin-staff-conflict">
                        {conflict}{' '}
                        <Button variant="link" className="admin-staff-deactivate" busy={deactivating} busyLabel={words.text('danger.deactivating')} onClick={onDeactivate}>
                            {words.text('danger.deactivate')}
                        </Button>
                    </Notice>
                )}
            </div>
        </section>
    );
}
