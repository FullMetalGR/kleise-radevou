import { Head, useForm, usePage } from '@inertiajs/react';
import { useShell } from '@/lib/strings';
import { Mark } from '@/shell/Mark';
import type { GuestProps } from '@/types';
import { Door } from './Door';
import { LoginForm } from './LoginForm';

/*
 * The sign-in page, outside the shell. The refusal prop is the sentence the server worded past the
 * sign-in limit; a wrong pair or an empty field comes back as the form's errors, keyed by the
 * field. The form shows each where it belongs, in the words the server wrote. The page's own title
 * and heading are the shell's login group, in the page's language.
 *
 * It is the door: the platform's mark by the brand's name, then one card with the business's name
 * over the heading and the form. A business's name has no longest length and may be one unbroken
 * word, so its line breaks anywhere and wraps inside the card. The page answered past the sign-in
 * limit is rendered before the host's shared props, so it carries neither name and shows neither.
 */

const LOGIN_URL = '/admin/login';

export default function Login({ refusal }: { refusal: string | null }) {
    const page = usePage<Partial<GuestProps>>();
    const form = useForm({ email: '', password: '' });
    const shell = useShell();

    return (
        <>
            <Head title={shell.text('login.title')} />
            <Door className="admin-login" mark={page.props.brand === undefined ? undefined : <Mark name={page.props.brand.name} />}>
                <div className="flex flex-col gap-1">
                    {page.props.tenant !== undefined && <p className="admin-login__business text-body-sm text-muted-foreground wrap-anywhere">{page.props.tenant.name}</p>}
                    <h1 className="text-dialog-title">{shell.text('login.heading')}</h1>
                </div>
                <LoginForm
                    email={form.data.email}
                    password={form.data.password}
                    refusal={refusal}
                    errors={form.errors}
                    processing={form.processing}
                    onEmail={(value) => form.setData('email', value)}
                    onPassword={(value) => form.setData('password', value)}
                    onSubmit={() => form.post(LOGIN_URL, { onFinish: () => form.reset('password') })}
                    large
                />
            </Door>
        </>
    );
}
