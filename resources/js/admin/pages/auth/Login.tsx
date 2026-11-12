import { Head, useForm } from '@inertiajs/react';
import { useShell } from '@/lib/strings';
import { LoginForm } from './LoginForm';

/*
 * The sign-in page, outside the shell. The refusal prop is the sentence the server worded past the
 * sign-in limit; a wrong pair or an empty field comes back as the form's errors, keyed by the
 * field. The form shows each where it belongs, in the words the server wrote. The page's own title
 * and heading are the shell's login group, in the page's language.
 */

const LOGIN_URL = '/admin/login';

export default function Login({ refusal }: { refusal: string | null }) {
    const form = useForm({ email: '', password: '' });
    const shell = useShell();

    return (
        <main className="admin-login mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-6 p-6">
            <Head title={shell.text('login.title')} />
            <h1 className="text-2xl font-semibold">{shell.text('login.heading')}</h1>
            <LoginForm
                email={form.data.email}
                password={form.data.password}
                refusal={refusal}
                errors={form.errors}
                processing={form.processing}
                onEmail={(value) => form.setData('email', value)}
                onPassword={(value) => form.setData('password', value)}
                onSubmit={() => form.post(LOGIN_URL, { onFinish: () => form.reset('password') })}
            />
        </main>
    );
}
