import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { CentralProps } from '@/types';
import { type SignupCategory, type SignupData, SignupForm, type SignupLegal } from './SignupForm';
import type { TurnstileWidget } from './Turnstile';

/*
 * The signup page on the central host, outside the shell, inside the site's header and footer the
 * central root view frames it with and the brand's scope its layout wears, so it fills no screen
 * of its own. Every sentence it shows is the server's, the strings prop the central adapter
 * shares in the request's language: the page holds none of its own. The POST answers with the
 * pending page, or the form's errors keyed by field; past the limit, and on an expired link, the
 * page comes back with the refusal prop worded. The timezone starts at Europe/Athens and the
 * currency at EUR, both editable. While the server hands the page Turnstile's widget, the POST
 * carries the widget's token in a field of its own, and every refused answer empties it and
 * mounts the widget anew; without the widget the POST carries no such field. The form posts where
 * the server says, the POST's path in the page's language, under /el for the Greek form. The
 * terms box starts unticked, and the two legal pages it links are the server's, in the page's
 * language.
 */

type Props = { categories: SignupCategory[]; timezones: string[]; centralHost: string; action: string; legal: SignupLegal; refusal: string | null; turnstile: TurnstileWidget | null };

export default function Signup({ categories, timezones, centralHost, action, legal, refusal, turnstile }: Props) {
    const { strings } = usePage<CentralProps>().props;
    const form = useForm<SignupData>({ slug: '', name: '', category: '', timezone: 'Europe/Athens', currency: 'EUR', owner_name: '', owner_email: '', password: '', terms: '', turnstile_token: '' });
    const [attempt, setAttempt] = useState(0);

    form.transform(({ turnstile_token, ...fields }) => (turnstile === null ? fields : { ...fields, turnstile_token }));

    return (
        <main className="admin-signup mx-auto flex max-w-md flex-col gap-6 px-6 py-12">
            <Head title={strings.form.title} />
            <h1 className="text-2xl font-semibold">{strings.form.heading}</h1>
            <SignupForm
                data={form.data}
                strings={strings}
                categories={categories}
                timezones={timezones}
                centralHost={centralHost}
                legal={legal}
                refusal={refusal}
                turnstile={turnstile}
                attempt={attempt}
                errors={form.errors}
                processing={form.processing}
                onChange={(field, value) => form.setData(field, value)}
                onSubmit={() =>
                    form.post(action, {
                        onError: () => {
                            form.setData('turnstile_token', '');
                            setAttempt((count) => count + 1);
                        },
                        onFinish: () => form.reset('password'),
                    })
                }
            />
        </main>
    );
}
