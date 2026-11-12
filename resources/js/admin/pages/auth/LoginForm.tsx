import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Field, useFocusFirstFailing } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { useShell } from '@/lib/strings';

/*
 * The door's form, as it renders: an email and a password, the server's sentence when it
 * refused, and a button that is busy while the answer is on its way. No remember-me control and
 * no password reset. Each label points at its input by id, the field shell's rule.
 *
 * Every refusal here is a 4xx, a worded answer, so none of it is an alert. A refused pair or an
 * empty field comes back as the form's errors and is that field's error through the field shell,
 * which references it and marks the control invalid, and focus moves to the first field so
 * marked once the answer lands. The sign-in limit names no field and is a polite notice above
 * the button, leaving focus where it was.
 *
 * The console's door renders the same form on its own host, with a button class of its own so a
 * walk never reads one door's control for the other's. Its words are the shell's login group,
 * from the strings the form is given: the admin's in the page's language, the console's in
 * English from the console's adapter.
 */

type Props = {
    email: string;
    password: string;
    refusal: string | null;
    errors: { email?: string; password?: string };
    processing: boolean;
    onEmail: (value: string) => void;
    onPassword: (value: string) => void;
    onSubmit: () => void;
    submitClassName?: string;
};

export function LoginForm({ email, password, refusal, errors, processing, onEmail, onPassword, onSubmit, submitClassName = 'admin-login-submit' }: Props) {
    const shell = useShell();
    const form = useFocusFirstFailing(errors);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };

    return (
        <form ref={form} className="flex flex-col gap-4" onSubmit={submit} noValidate>
            <Field id="login-email" label={shell.text('login.email')} error={errors.email}>
                {(control) => <TextInput {...control} name="email" type="email" autoComplete="username" value={email} onChange={(event) => onEmail(event.target.value)} />}
            </Field>
            <Field id="login-password" label={shell.text('login.password')} error={errors.password}>
                {(control) => <TextInput {...control} name="password" type="password" autoComplete="current-password" value={password} onChange={(event) => onPassword(event.target.value)} />}
            </Field>
            {refusal !== null && (
                <Notice kind="warning" className="admin-login-problem">
                    {refusal}
                </Notice>
            )}
            <Button type="submit" variant="primary" className={submitClassName} busy={processing} busyLabel={shell.text('login.submitting')}>
                {shell.text('login.submit')}
            </Button>
        </form>
    );
}
