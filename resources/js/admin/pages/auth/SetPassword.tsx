import { Head, useForm, usePage } from '@inertiajs/react';
import { type ShellKey, useShell } from '@/lib/strings';
import { Mark } from '@/shell/Mark';
import type { GuestProps } from '@/types';
import { Door } from './Door';
import { SetPasswordForm } from './SetPasswordForm';

/*
 * The set-password link's page, on the tenant's own host outside the shell: the link the console
 * mailed a user opens it, and the user chooses the password they sign in with. The form posts to
 * the URL the page was opened at, which is the link itself, so no prop carries the token. A
 * refused password comes back on the field; a set, and a link that has died since the page opened,
 * land on the sign-in page with the server's flash. Nothing is optimistic. Its words are the
 * shell's set_password group, in the page's language; floor is PasswordRule's floor, which the
 * help line names. It is the door, as the sign-in page is: the platform's mark by the brand's
 * name, then one card with the business's name over the heading and the form. The name's line
 * breaks anywhere, as it does there, so one long unbroken word wraps inside the card.
 */

/** The page's title, also its heading: a key of the shell's section. */
export const SET_PASSWORD_HEADING = 'set_password.title' satisfies ShellKey;

export default function SetPassword() {
    const page = usePage<Partial<GuestProps> & { floor: number }>();
    const form = useForm({ password: '' });
    const shell = useShell();

    return (
        <>
            <Head title={shell.text(SET_PASSWORD_HEADING)} />
            <Door className="admin-set-password" mark={page.props.brand === undefined ? undefined : <Mark name={page.props.brand.name} />}>
                <div className="flex flex-col gap-1">
                    {page.props.tenant !== undefined && <p className="admin-set-password__business text-body-sm text-muted-foreground wrap-anywhere">{page.props.tenant.name}</p>}
                    <h1 className="text-dialog-title">{shell.text(SET_PASSWORD_HEADING)}</h1>
                </div>
                <SetPasswordForm
                    password={form.data.password}
                    floor={page.props.floor}
                    error={form.errors.password}
                    processing={form.processing}
                    onPassword={(value) => form.setData('password', value)}
                    onSubmit={() => form.post(page.url, { onFinish: () => form.reset('password') })}
                />
            </Door>
        </>
    );
}
