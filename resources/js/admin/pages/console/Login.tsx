import { Head, useForm } from '@inertiajs/react';
import { ConsoleWordmark } from '@/shell/Wordmark';
import type { Brand } from '@/types';
import { Door } from '../auth/Door';
import { LoginForm } from '../auth/LoginForm';

/*
 * The console's door, on the console's host and outside the console's shell, as the admin's door
 * renders outside the admin's, and in the same frame: the console's wordmark at its head, then one
 * card holding the heading and the admin's form, an email, a password and a large Sign in button
 * across the card, posting to the host's /login. The refusal prop is the sentence the server words
 * past the sign-in limit, and a refused pair comes back as the form's errors. The wordmark names
 * the console by the brand prop's public name, which the server hands the door past the limit too.
 */

const LOGIN_URL = '/login';

export default function Login({ refusal, brand }: { refusal: string | null; brand: Brand }) {
    const form = useForm({ email: '', password: '' });

    return (
        <>
            <Head title="Console sign in" />
            <Door
                className="admin-console-login"
                mark={
                    <p className="admin-console-login-name font-semibold">
                        <ConsoleWordmark name={brand.name} />
                    </p>
                }
            >
                <h1 className="text-dialog-title">Sign in</h1>
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
                    large
                />
            </Door>
        </>
    );
}
