import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Field, useFocusFirstFailing } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { useShell } from '@/lib/strings';

/*
 * The set-password link's form, as it renders: one field for the new password and a Set password
 * button that is busy while the answer is on its way. The label points at its input by id, the
 * field shell's rule, and the help line gives the floor of PasswordRule, which the page hands it
 * as floor. Its words are the shell's set_password group.
 *
 * A refused password is a 4xx, a worded answer, so it is never an alert: it comes back as the
 * form's error and is the field's error through the field shell, which marks the control invalid,
 * and focus moves to it once the answer lands.
 */

type Props = {
    password: string;
    /** PasswordRule's floor in characters, which the help line names. */
    floor: number;
    error: string | undefined;
    processing: boolean;
    onPassword: (value: string) => void;
    onSubmit: () => void;
};

export function SetPasswordForm({ password, floor, error, processing, onPassword, onSubmit }: Props) {
    const form = useFocusFirstFailing(error);
    const shell = useShell();
    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };

    return (
        <form ref={form} className="flex flex-col gap-4" onSubmit={submit} noValidate>
            <Field id="set-password" label={shell.text('set_password.password')} help={shell.text('set_password.help', { floor: String(floor) })} error={error}>
                {(control) => <TextInput {...control} name="password" type="password" autoComplete="new-password" value={password} onChange={(event) => onPassword(event.target.value)} />}
            </Field>
            <Button type="submit" variant="primary" className="admin-set-password-submit" busy={processing} busyLabel={shell.text('set_password.submitting')}>
                {shell.text('set_password.submit')}
            </Button>
        </form>
    );
}
