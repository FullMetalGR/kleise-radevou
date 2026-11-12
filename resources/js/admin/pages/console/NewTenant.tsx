import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field, useFocusFirstFailing } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { Select } from '@/components/ui/select';
import { useShell } from '@/lib/strings';
import { owningFailure } from '@/lib/visits';
import { upperCurrency } from '@/settings/model';
import { useToasts } from '@/toast/toasts';
import type { ConsoleProps } from '@/types';
import { chosenIn, FeatureBoxes, type FeatureChoice, useFeatureChoice } from './FeatureBoxes';

/*
 * The free tenant's form, inside the console's shell: the one exception to the no-trial rule,
 * granted by the operator. It takes what the signup's form takes, the slug with the address it
 * becomes shown as it is typed, the business and its kind or none, its clock, Europe/Athens to
 * start, and its currency, EUR to start and upper-cased on every keystroke, and the owner's name
 * and email; no password, since the owner sets their own through the link the creation mails; the
 * language the platform writes to the tenant's people in, English to start with Greek beside it,
 * written to the row and followed by the set-password mail, its two labels the console's own
 * English words since the console stays English; and one checkbox per feature.
 *
 * Nothing is optimistic. Create tenant shows its busy state until the server's answer lands, one
 * submit at a time; a created tenant is the redirect to its detail with the flash naming where the
 * owner's link went. Every refusal is the server's sentence on its field, the slug's among them,
 * and focus moves to the first field so marked; a failure the server could not word is an error
 * toast that stays, everything typed kept.
 */

type Category = { value: string; label: string };

type Props = ConsoleProps & { categories: Category[]; timezones: string[]; centralHost: string; features: FeatureChoice[] };

type NewTenantData = {
    slug: string;
    name: string;
    category: string;
    timezone: string;
    currency: string;
    owner_name: string;
    owner_email: string;
    locale: string;
};

type Errors = Partial<Record<keyof NewTenantData | 'features', string>>;

/** Where the form posts. */
export const STORE_PATH = '/tenants';

export const NEW_TENANT_NOTE = 'The account is free of charge, with no trial and no end. The owner is emailed a link to set their own password, and gets in through it.';

export const CREATE_FAILED = 'The tenant could not be created. Please try again.';

const NO_CATEGORY = 'None';

/** The two languages a tenant's row may carry, named by the console's own English words. */
const LANGUAGES = [
    { value: 'en', label: 'English' },
    { value: 'el', label: 'Greek' },
];

const STARTING: NewTenantData = { slug: '', name: '', category: '', timezone: 'Europe/Athens', currency: 'EUR', owner_name: '', owner_email: '', locale: 'en' };

export default function NewTenant({ categories, timezones, centralHost, features }: Props) {
    const toasts = useToasts();
    const shell = useShell();
    const [data, setData] = useState<NewTenantData>(STARTING);
    const { chosen, toggle } = useFeatureChoice([]);
    const [errors, setErrors] = useState<Errors>({});
    const [creating, setCreating] = useState(false);
    const form = useFocusFirstFailing(errors);

    const set = (field: keyof NewTenantData, value: string) => setData((was) => ({ ...was, [field]: value }));

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (creating) {
            return;
        }

        const owned = owningFailure((failure) => toasts.push('error', failure.sentence ?? CREATE_FAILED), shell);

        router.post(STORE_PATH, { ...data, features: chosenIn(features, chosen) }, {
            onStart: () => {
                setCreating(true);
                owned.onStart();
            },
            onFinish: () => {
                setCreating(false);
                owned.onFinish();
            },
            onError: setErrors,
        });
    };

    const text = (field: keyof NewTenantData, label: string, type = 'text', help?: string) => (
        <Field id={`new-tenant-${field}`} label={label} help={help} error={errors[field]}>
            {(control) => <TextInput {...control} name={field} type={type} autoComplete="off" value={data[field]} onChange={(event) => set(field, event.target.value)} />}
        </Field>
    );

    return (
        <>
            <Head title="New tenant" />
            <div className="admin-new-tenant-screen flex max-w-xl flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <Link href="/" className="admin-new-tenant-back text-sm underline-offset-4 hover:underline">
                        All tenants
                    </Link>
                    <h1 className="text-2xl font-semibold">New tenant</h1>
                </div>
                <Notice kind="info" className="admin-new-tenant__note">
                    {NEW_TENANT_NOTE}
                </Notice>
                <form ref={form} className="admin-new-tenant flex flex-col gap-4" onSubmit={submit} noValidate>
                    {text('slug', 'Address', 'text', `${data.slug.trim().toLowerCase() || 'their-name'}.${centralHost}`)}
                    {text('name', 'Business name')}
                    <Field id="new-tenant-category" label="Kind of business" error={errors.category}>
                        {(control) => <Select {...control} name="category" value={data.category} onChange={(value) => set('category', value)} options={[{ value: '', label: NO_CATEGORY }, ...categories]} />}
                    </Field>
                    <Field id="new-tenant-timezone" label="Timezone" error={errors.timezone}>
                        {(control) => (
                            <Select
                                {...control}
                                name="timezone"
                                value={data.timezone}
                                onChange={(value) => set('timezone', value)}
                                options={[{ value: '', label: 'Choose a timezone' }, ...timezones.map((zone) => ({ value: zone, label: zone }))]}
                            />
                        )}
                    </Field>
                    <Field id="new-tenant-currency" label="Currency" help="Three letters, such as EUR." error={errors.currency}>
                        {(control) => <TextInput {...control} name="currency" value={data.currency} maxLength={3} autoComplete="off" onChange={(event) => set('currency', upperCurrency(event.target.value))} />}
                    </Field>
                    {text('owner_name', 'Owner\'s name')}
                    {text('owner_email', 'Owner\'s email', 'email')}
                    <Field id="new-tenant-locale" label="Language" help="The language the platform's emails to the business are written in." error={errors.locale}>
                        {(control) => <Select {...control} name="locale" value={data.locale} onChange={(value) => set('locale', value)} options={LANGUAGES} />}
                    </Field>
                    <fieldset
                        id="new-tenant-features"
                        className="admin-new-tenant__features flex flex-col gap-3"
                        tabIndex={-1}
                        aria-invalid={errors.features === undefined ? undefined : true}
                        aria-describedby={errors.features === undefined ? undefined : 'new-tenant-features-error'}
                    >
                        <legend className="mb-1 text-sm font-medium">Features</legend>
                        <FeatureBoxes
                            idPrefix="new-tenant-feature"
                            className="admin-new-tenant__feature"
                            offered={features}
                            chosen={chosen}
                            onToggle={toggle}
                            error={errors.features ?? null}
                            errorId="new-tenant-features-error"
                        />
                    </fieldset>
                    <div className="flex">
                        <Button type="submit" variant="primary" className="admin-new-tenant-submit" busy={creating} busyLabel="Creating...">
                            Create tenant
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
