import { Head, useForm, usePage } from '@inertiajs/react';
import { type ShellKey, useShell } from '@/lib/strings';
import type { GuestProps } from '@/types';
import { SetPasswordForm } from './SetPasswordForm';

/*
 * The set-password link's page, on the tenant's own host outside the shell: the link the console
 * mailed a user opens it, and the user chooses the password they sign in with. The form posts to
 * the URL the page was opened at, which is the link itself, so no prop carries the token. A
 * refused password comes back on the field; a set, and a link that has died since the page opened,
 * land on the sign-in page with the server's flash. Nothing is optimistic. Its words are the
 * shell's set_password group, in the page's language; floor is PasswordRule's floor, which the
 * help line names.
 */

/** The page's title, also its heading: a key of the shell's section. */
export const SET_PASSWORD_HEADING = 'set_password.title' satisfies ShellKey;

export default function SetPassword() {
    const page = usePage<Partial<GuestProps> & { floor: number }>();
    const form = useForm({ password: '' });
    const shell = useShell();

    return (
        <main className="admin-set-password mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-6 p-6">
            <Head title={shell.text(SET_PASSWORD_HEADING)} />
            <div className="flex flex-col gap-1">
                {page.props.tenant !== undefined && <p className="admin-set-password__business text-sm text-muted-foreground">{page.props.tenant.name}</p>}
                <h1 className="text-2xl font-semibold">{shell.text(SET_PASSWORD_HEADING)}</h1>
            </div>
            <SetPasswordForm
                password={form.data.password}
                floor={page.props.floor}
                error={form.errors.password}
                processing={form.processing}
                onPassword={(value) => form.setData('password', value)}
                onSubmit={() => form.post(page.url, { onFinish: () => form.reset('password') })}
            />
        </main>
    );
}
