import { Field } from '@/components/ui/field';
import { TextInput, Textarea } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { MapLine } from '@/settings/MapLine';
import type { BusinessMap, Problems, ProfileField, SettingsForm } from '@/settings/model';
import { type SettingsKey, type SettingsWords, useSettings } from '@/settings/words';

/*
 * The business page's text in the Booking page section: About, the address, Phone, Public email,
 * the four links and Important info, in that order. Each field is plain text the hosted page
 * renders above the flow where it is set and leaves out where it is not; Important info is the
 * one text the review step and the confirmation carry. The country is a select of two-letter
 * codes starting on GR, named from countries.php in the user's language. Under the address fields
 * stands the map's line, read from the props and not the form.
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

type Props = {
    form: SettingsForm;
    problems: Problems;
    set: (field: ProfileField) => (value: string) => void;
    map: BusinessMap;
    countries: Readonly<Record<string, string>>;
};

export function ProfileFields({ form, problems, set, map, countries }: Props) {
    const words = useSettings();
    const text = (field: ProfileField, label: SettingsKey, help?: SettingsKey, extra: { type?: string; autoComplete?: string; inputMode?: 'tel' | 'email' | 'url' } = {}) => (
        <Field id={`settings-${field.replaceAll('_', '-')}`} label={words.text(label)} help={help === undefined ? undefined : words.text(help)} error={problems[field] ?? null}>
            {(control) => <TextInput {...control} value={form[field]} autoComplete={extra.autoComplete ?? 'off'} type={extra.type} inputMode={extra.inputMode} onChange={(event) => set(field)(event.target.value)} />}
        </Field>
    );
    const area = (field: 'about' | 'important_info', label: SettingsKey, help: SettingsKey) => (
        <Field id={`settings-${field.replaceAll('_', '-')}`} label={words.text(label)} help={words.text(help)} error={problems[field] ?? null} className="sm:col-span-2">
            {(control) => <Textarea {...control} rows={4} value={form[field]} onChange={(event) => set(field)(event.target.value)} />}
        </Field>
    );

    return (
        <>
            {area('about', 'profile.about', ABOUT_HELP)}
            {text('address_line1', 'profile.address_line1', ADDRESS_HELP)}
            {text('address_line2', 'profile.address_line2')}
            {text('city', 'profile.city')}
            {text('postal_code', 'profile.postal_code')}
            <Field id="settings-country" label={words.text('profile.country')} error={problems.country ?? null}>
                {(control) => <Select {...control} options={countryOptions(countries, form.country, words)} value={form.country} onChange={set('country')} />}
            </Field>
            <MapLine map={map} />
            {text('phone', 'profile.phone', PHONE_HELP, { type: 'tel', inputMode: 'tel' })}
            {text('public_email', 'profile.public_email', PUBLIC_EMAIL_HELP, { type: 'email', inputMode: 'email' })}
            {text('website', 'profile.website', LINK_HELP, { inputMode: 'url' })}
            {text('instagram', 'profile.instagram', LINK_HELP, { inputMode: 'url' })}
            {text('facebook', 'profile.facebook', LINK_HELP, { inputMode: 'url' })}
            {text('google_reviews_url', 'profile.google_reviews', REVIEWS_HELP, { inputMode: 'url' })}
            {area('important_info', 'profile.important_info', IMPORTANT_INFO_HELP)}
        </>
    );
}
