import { type FormEvent, Fragment } from 'react';
import { tagged } from '../../../booking/tagged';
import { Button } from '@/components/ui/button';
import { Field, useFocusFirstFailing } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { Select } from '@/components/ui/select';
import { upperCurrency } from '@/settings/model';
import type { SignupStrings } from '@/types';
import { Turnstile, type TurnstileWidget } from './Turnstile';

/*
 * The signup form as it renders: the slug with the address it becomes shown as the person types,
 * the business and its kind, its clock and its currency, and the owner. No plan and no card: the
 * signup starts a trial once the owner's email is confirmed. The currency is upper-cased on every
 * keystroke and holds three characters, as the Settings screen's is, so the server's rule, which
 * folds no case, never refuses three typed letters. Every label, placeholder, help line, group
 * name and button is a string of signup.php the server handed the page in the request's language;
 * the form holds no sentence of its own. Every refusal is the server's sentence on its field, and
 * focus moves to the first field so marked once the answer lands; the limit and an expired link
 * name no field and are a polite notice above the button, leaving focus where it was. The button
 * is busy while the answer is on its way.
 *
 * While the server hands the form Cloudflare Turnstile's widget, the anti-spam check sits above
 * the button and its token is the form's turnstile_token field, its refusal named under the
 * widget as any field's is; `attempt` is the count of refused answers, and each one mounts the
 * widget anew for a fresh token. Without the widget there is no check and no such field.
 *
 * The terms' box sits after the password and above the anti-spam check: one required checkbox
 * labelled by one sentence of signup.php, whose two link texts sit between the named tags <terms>
 * and <privacy>; tagged() turns each pair into a link to the legal page the server named in the
 * form's language, opening in a new tab so the form keeps what was typed, and nothing of the
 * sentence is read as markup. The box has no help line of its own; its refusal shows under it as
 * every field's does, and the box announces it as the field shell marks a control, so the focus
 * move finds it. Ticked, the field posts TERMS_TICKED, one value of the server's accepted rule;
 * unticked, it posts nothing the rule accepts.
 */

/** What the terms box posts once ticked, one value of the server's accepted rule. */
export const TERMS_TICKED = '1';

export type SignupCategory = { value: string; label: string };

export type SignupData = {
    slug: string;
    name: string;
    category: string;
    timezone: string;
    currency: string;
    owner_name: string;
    owner_email: string;
    password: string;
    terms: string;
    turnstile_token: string;
};

export type SignupErrors = Partial<Record<keyof SignupData, string>>;

/** The two legal pages the terms box links, in the form's language. */
export type SignupLegal = { terms: string; privacy: string };

type Props = {
    data: SignupData;
    strings: SignupStrings;
    categories: SignupCategory[];
    timezones: string[];
    centralHost: string;
    legal: SignupLegal;
    refusal: string | null;
    turnstile: TurnstileWidget | null;
    attempt: number;
    errors: SignupErrors;
    processing: boolean;
    onChange: (field: keyof SignupData, value: string) => void;
    onSubmit: () => void;
};

export function SignupForm({ data, strings, categories, timezones, centralHost, legal, refusal, turnstile, attempt, errors, processing, onChange, onSubmit }: Props) {
    const form = useFocusFirstFailing(errors);
    const labels = strings.form;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };
    const text = (field: keyof SignupData, label: string, type = 'text', autoComplete = 'off') => (
        <Field id={`signup-${field}`} label={label} error={errors[field]}>
            {(control) => <TextInput {...control} name={field} type={type} autoComplete={autoComplete} value={data[field]} onChange={(event) => onChange(field, event.target.value)} />}
        </Field>
    );
    const notice = refusal;
    const check = errors.turnstile_token ?? '';
    const terms = errors.terms ?? '';
    const link = (href: string) => (linkText: string) => (
        <a href={href} target="_blank" rel="noopener noreferrer" className="admin-signup-terms__link underline">
            {linkText}
        </a>
    );

    return (
        <form ref={form} className="flex flex-col gap-4" onSubmit={submit} noValidate>
            <Field id="signup-slug" label={labels.slug} help={`${data.slug.trim().toLowerCase() || labels.slug_example}.${centralHost}`} error={errors.slug}>
                {(control) => <TextInput {...control} name="slug" autoComplete="off" value={data.slug} onChange={(event) => onChange('slug', event.target.value)} />}
            </Field>
            {text('name', labels.name, 'text', 'organization')}
            <Field id="signup-category" label={labels.category} error={errors.category}>
                {(control) => (
                    <Select
                        {...control}
                        name="category"
                        value={data.category}
                        onChange={(value) => onChange('category', value)}
                        options={[{ value: '', label: labels.category_placeholder }, ...categories]}
                    />
                )}
            </Field>
            <Field id="signup-timezone" label={labels.timezone} error={errors.timezone}>
                {(control) => (
                    <Select
                        {...control}
                        name="timezone"
                        value={data.timezone}
                        onChange={(value) => onChange('timezone', value)}
                        options={[{ value: '', label: labels.timezone_placeholder }, ...timezones.map((zone) => ({ value: zone, label: zone }))]}
                    />
                )}
            </Field>
            <Field id="signup-currency" label={labels.currency} help={labels.currency_help} error={errors.currency}>
                {(control) => <TextInput {...control} name="currency" value={data.currency} maxLength={3} autoComplete="off" onChange={(event) => onChange('currency', upperCurrency(event.target.value))} />}
            </Field>
            {text('owner_name', labels.owner_name, 'text', 'name')}
            {text('owner_email', labels.owner_email, 'email', 'email')}
            {text('password', labels.password, 'password', 'new-password')}
            <div className="admin-field admin-signup-terms flex flex-col gap-1">
                <div className="flex items-start gap-2">
                    <input
                        type="checkbox"
                        id="signup-terms"
                        name="terms"
                        className="admin-check-field__tick mt-0.5"
                        checked={data.terms === TERMS_TICKED}
                        aria-invalid={terms === '' ? undefined : true}
                        aria-describedby={terms === '' ? undefined : 'signup-terms-error'}
                        onChange={(event) => onChange('terms', event.target.checked ? TERMS_TICKED : '')}
                    />
                    <label htmlFor="signup-terms" className="admin-check-field__label text-sm">
                        {tagged(labels.terms, { terms: link(legal.terms), privacy: link(legal.privacy) }).map((part, index) => (
                            <Fragment key={index}>{part}</Fragment>
                        ))}
                    </label>
                </div>
                {terms !== '' && (
                    <p id="signup-terms-error" className="admin-field__error text-destructive text-sm">
                        {terms}
                    </p>
                )}
            </div>
            {turnstile !== null && (
                <div
                    id="signup-turnstile_token"
                    className="admin-field admin-signup-check flex flex-col gap-1"
                    role="group"
                    aria-label={labels.check}
                    tabIndex={-1}
                    aria-invalid={check === '' ? undefined : true}
                    aria-describedby={check === '' ? undefined : 'signup-turnstile_token-error'}
                >
                    <Turnstile key={attempt} siteKey={turnstile.siteKey} script={turnstile.script} onToken={(token) => onChange('turnstile_token', token)} />
                    {check !== '' && (
                        <p id="signup-turnstile_token-error" className="admin-field__error text-destructive text-sm">
                            {check}
                        </p>
                    )}
                </div>
            )}
            {notice !== null && (
                <Notice kind="warning" className="admin-signup-problem">
                    {notice}
                </Notice>
            )}
            <Button type="submit" variant="primary" className="admin-signup-submit" busy={processing} busyLabel={labels.submitting}>
                {labels.submit}
            </Button>
        </form>
    );
}
