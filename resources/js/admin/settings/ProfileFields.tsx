import { Field } from '@/components/ui/field';
import { TextInput, Textarea } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { MapLine } from '@/settings/MapLine';
import type { BasicField, BusinessMap, Problems, ProfileField, SettingsForm } from '@/settings/model';
import { type SettingsKey, type SettingsWords, useSettings } from '@/settings/words';

/*
 * The business page's text in the Booking page section: About, the address, Phone, Public email,
 * the four links and Important info, in that order. Each field is plain text the hosted page
 * renders above the flow where it is set and leaves out where it is not; Important info is the
 * one text the review step and the confirmation carry. The country is a select of two-letter
 * codes starting on GR, named from countries.php in the user's language. Under the address fields
 * stands the map's line, read from the props and not the form.
 *
 * About, the address with the map's line, and Phone are ProfileBasics, the part the setup wizard's
 * Business profile step asks for, drawn the same there: the same fields, ids, words and checks.
 */

/*
 * The fields' words are keys of the settings section's profile group: each constant that held a
 * sentence holds its key and keeps its name.
 */

const ABOUT_HELP = 'profile.about_help' satisfies SettingsKey;

const ADDRESS_HELP = 'profile.address_help' satisfies SettingsKey;

const PHONE_HELP = 'profile.phone_help' satisfies SettingsKey;

const PUBLIC_EMAIL_HELP = 'profile.public_email_help' satisfies SettingsKey;

const LINK_HELP = 'profile.link_help' satisfies SettingsKey;

const REVIEWS_HELP = 'profile.reviews_help' satisfies SettingsKey;

const IMPORTANT_INFO_HELP = 'profile.important_info_help' satisfies SettingsKey;

/**
 * The countries the select offers: every code of lang/{locale}/countries.php with its name in the
 * user's language, the page's countries prop, and the stored code added as itself when the file
 * does not hold it; sorted by the language's collation, never by a name ICU would give the
 * region.
 */
function countryOptions(countries: Readonly<Record<string, string>>, stored: string, words: SettingsWords): { value: string; label: string }[] {
    const collator = new Intl.Collator(words.locale);
    const options = Object.entries(countries).map(([code, country]) => ({ value: code, label: words.text('profile.country_option', { country, code }) }));
    const held = stored === '' || Object.hasOwn(countries, stored) ? options : [...options, { value: stored, label: stored }];

    return held.sort((one, other) => collator.compare(one.label, other.label));
}

/** What a field is drawn from: the form's values, the checks that fail, the setter and the words. */
type Parts<F extends ProfileField> = {
    form: Readonly<Record<F, string>>;
    problems: Partial<Record<F, string>>;
    set: (field: F) => (value: string) => void;
    words: SettingsWords;
};

type Extra = { type?: string; autoComplete?: string; inputMode?: 'tel' | 'email' | 'url' };

function fieldId(field: ProfileField): string {
    return `settings-${field.replaceAll('_', '-')}`;
}

function textField<F extends ProfileField>({ form, problems, set, words }: Parts<F>, field: F, label: SettingsKey, help?: SettingsKey, extra: Extra = {}) {
    return (
        <Field id={fieldId(field)} label={words.text(label)} help={help === undefined ? undefined : words.text(help)} error={problems[field] ?? null}>
            {(control) => <TextInput {...control} value={form[field]} autoComplete={extra.autoComplete ?? 'off'} type={extra.type} inputMode={extra.inputMode} onChange={(event) => set(field)(event.target.value)} />}
        </Field>
    );
}

function areaField<F extends ProfileField>({ form, problems, set, words }: Parts<F>, field: F, label: SettingsKey, help: SettingsKey) {
    return (
        <Field id={fieldId(field)} label={words.text(label)} help={words.text(help)} error={problems[field] ?? null} className="@xl:col-span-2">
            {(control) => <Textarea {...control} rows={4} value={form[field]} onChange={(event) => set(field)(event.target.value)} />}
        </Field>
    );
}

type BasicsProps = {
    form: Readonly<Record<BasicField, string>>;
    problems: Partial<Record<BasicField, string>>;
    set: (field: BasicField) => (value: string) => void;
    map: BusinessMap;
    countries: Readonly<Record<string, string>>;
};

/** About, the address with the map's line under it, and Phone. */
export function ProfileBasics({ form, problems, set, map, countries }: BasicsProps) {
    const words = useSettings();
    const parts: Parts<BasicField> = { form, problems, set, words };

    return (
        <>
            {areaField(parts, 'about', 'profile.about', ABOUT_HELP)}
            {textField(parts, 'address_line1', 'profile.address_line1', ADDRESS_HELP)}
            {textField(parts, 'address_line2', 'profile.address_line2')}
            {textField(parts, 'city', 'profile.city')}
            {textField(parts, 'postal_code', 'profile.postal_code')}
            <Field id="settings-country" label={words.text('profile.country')} error={problems.country ?? null}>
                {(control) => <Select {...control} options={countryOptions(countries, form.country, words)} value={form.country} onChange={set('country')} />}
            </Field>
            <MapLine map={map} />
            {textField(parts, 'phone', 'profile.phone', PHONE_HELP, { type: 'tel', inputMode: 'tel' })}
        </>
    );
}

type Props = {
    form: SettingsForm;
    problems: Problems;
    set: (field: ProfileField) => (value: string) => void;
    map: BusinessMap;
    countries: Readonly<Record<string, string>>;
};

export function ProfileFields({ form, problems, set, map, countries }: Props) {
    const words = useSettings();
    const parts: Parts<ProfileField> = { form, problems, set, words };

    return (
        <>
            <ProfileBasics form={form} problems={problems} set={set} map={map} countries={countries} />
            {textField(parts, 'public_email', 'profile.public_email', PUBLIC_EMAIL_HELP, { type: 'email', inputMode: 'email' })}
            {textField(parts, 'website', 'profile.website', LINK_HELP, { inputMode: 'url' })}
            {textField(parts, 'instagram', 'profile.instagram', LINK_HELP, { inputMode: 'url' })}
            {textField(parts, 'facebook', 'profile.facebook', LINK_HELP, { inputMode: 'url' })}
            {textField(parts, 'google_reviews_url', 'profile.google_reviews', REVIEWS_HELP, { inputMode: 'url' })}
            {areaField(parts, 'important_info', 'profile.important_info', IMPORTANT_INFO_HELP)}
        </>
    );
}
