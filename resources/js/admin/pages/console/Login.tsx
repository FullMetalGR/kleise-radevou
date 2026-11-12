import { Head, useForm } from '@inertiajs/react';
import { ConsoleWordmark } from '@/shell/Wordmark';
import type { Brand } from '@/types';
import { LoginForm } from '../auth/LoginForm';

/*
 * The console's door, on the console's host and outside the console's shell, as the admin's door
 * renders outside the admin's. The admin's form: an email, a password and a Sign in button,
 * posting to the host's /login; the refusal prop is the sentence the server words past the
 * sign-in limit, and a refused pair comes back as the form's errors. Above it the console's
 * wordmark names the console by the brand prop's public name, which the server hands the door
 * past the limit too.
 */

const LOGIN_URL = '/login';

export default function Login({ refusal, brand }: { refusal: string | null; brand: Brand }) {
    const form = useForm({ email: '', password: '' });

    return (
        <main className="admin-console-login mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-6 p-6">
            <Head title="Console sign in" />
            <div className="flex flex-col gap-1">
                <p className="admin-console-login-name font-semibold">
                    <ConsoleWordmark name={brand.name} />
                </p>
                <h1 className="text-2xl font-semibold">Sign in</h1>
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
                submitClassName="admin-console-login-submit"
            />
        </main>
    );
}
