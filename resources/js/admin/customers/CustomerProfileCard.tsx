import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Field } from '@/components/ui/field';
import { TextInput } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import type { TenantClock } from '@/lib/format';
import { useOneWrite } from '@/lib/one-write';
import type { Profile } from './model';
import { type CustomersKey, useCustomers } from './words';

/*
 * A customer's Profile card: Tags, a text field of words with a comma between, its help line
 * naming the shape; Birthday on the admin's own date field in the tenant's date format like every
 * other admin date, optional and clearable, its calendar's month and year dropdowns reaching a
 * birthday decades back, from the earliest day the server takes to today; Preferred language, Not
 * recorded, Greek or English; and Save, which puts the three whole in one request to the
 * customer's profile route, PUT {page}/profile. The server trims the words, keeps each once and
 * refuses past its bounds with a sentence, shown in an error toast with what was typed kept; a
 * save that never reached it is the card's own sentence. Nothing is optimistic: the head's chips
 * and these fields read the server's answer, which the page mounts this card afresh on. Every
 * word is a key of the customers section.
 */

const SAVE = 'profile.save' satisfies CustomersKey;

const SAVING = 'profile.saving' satisfies CustomersKey;

const SAVE_ERROR = 'profile.error' satisfies CustomersKey;

type Props = { url: string; profile: Profile; earliest: string; clock: TenantClock; weekStart: number };

export function CustomerProfileCard({ url, profile, earliest, clock, weekStart }: Props) {
    const words = useCustomers();
    const write = useOneWrite({ save: words.text(SAVING) });
    const [tags, setTags] = useState(profile.tags.join(', '));
    const [birthday, setBirthday] = useState(profile.birthday ?? '');
    const [language, setLanguage] = useState<string>(profile.language ?? '');

    const save = () =>
        write.visit(
            'save',
            (failure) => failure.sentence ?? words.text(SAVE_ERROR),
            () => undefined,
            (options) => router.put(`${url}/profile`, { tags, birthday: birthday === '' ? null : birthday, language: language === '' ? null : language }, options),
        );

    return (
        <section className="admin-card admin-customer-profile flex flex-col gap-3 rounded-xl border p-4" aria-labelledby="customer-profile-head">
            <h2 id="customer-profile-head" className="font-semibold">
                {words.text('profile.title')}
            </h2>
            <Field id="customer-tags" label={words.text('profile.tags')} help={words.text('profile.tags_help')}>
                {(control) => <TextInput {...control} value={tags} onChange={(event) => setTags(event.target.value)} />}
            </Field>
            <div className="grid grid-cols-1 gap-3 desktop:grid-cols-2">
                <Field id="customer-birthday" label={words.text('profile.birthday')}>
                    {(control) => <DateField {...control} className="admin-customer-profile__birthday" value={birthday} onChange={setBirthday} clock={clock} weekStart={weekStart} earliest={earliest} />}
                </Field>
                <Field id="customer-language" label={words.text('profile.language')}>
                    {(control) => (
                        <Select
                            {...control}
                            className="w-full"
                            value={language}
                            onChange={setLanguage}
                            options={[
                                { value: '', label: words.text('profile.not_recorded') },
                                { value: 'el', label: words.text('profile.greek') },
                                { value: 'en', label: words.text('profile.english') },
                            ]}
                        />
                    )}
                </Field>
            </div>
            <div>
                <Button variant="primary" className="admin-customer-profile__save" busy={write.inFlight === 'save'} busyLabel={words.text(SAVING)} onClick={save}>
                    {words.text(SAVE)}
                </Button>
            </div>
        </section>
    );
}
