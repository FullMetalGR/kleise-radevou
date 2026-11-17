import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Field } from '@/components/ui/field';
import { useOneWrite } from '@/lib/one-write';
import { type KeysOf, type ShellKey, type ShellWords, type Strings, useShell } from '@/lib/strings';
import type { Failure } from '@/lib/visits';
import { COVER_ERROR, COVER_HELP, COVER_PREVIEW, COVER_URL, COVER_WORDS, LOGO_ERROR, LOGO_HELP, LOGO_PREVIEW, LOGO_URL, LOGO_WORDS, slotWords } from '@/settings/images';
import { ImageSlot } from '@/settings/ImageSlot';
import { BASIC_FIELDS, type BasicField, type BusinessMap, type Limits, type Logo, profileProblems, seedProfileOf } from '@/settings/model';
import { ProfileBasics } from '@/settings/ProfileFields';
import { type SettingsKey, SettingsProvider, settingsWordsOf, useSettings } from '@/settings/words';
import type { SettingsStrings, SharedProps } from '@/types';
import { useStepDirty, useStepMoves, useStepPage, useStepPrimary } from '../step';
import { forgetting, onField } from './refusal';

/*
 * The Business profile step: the logo, About, the address with the map's line, Phone and the
 * cover, drawn as the Settings screen draws them and read in its words, checked by its rules as
 * they are typed. The primary, Save and continue, sends those seven fields alone, an empty form
 * included, and once the save has landed the wizard moves on. The logo and the cover go through
 * the Settings screen's own uploads, each its own write that comes back to Home re-read, the form
 * kept as typed. One write is in flight at a time: while the save is, the images' buttons wait;
 * while an image's is, the primary waits, busy under the word the image's own button shows, its
 * reason said beside it. A write started on another step would cut an image's write short with
 * nothing said, so while one is on its way the step is busy, which holds the wizard's other moves
 * as a save does, and counts as holding an unsaved edit, so closing the wizard asks first; both
 * are over once the write ends, however it ends.
 */

/** The step's save, PUT, its body the seven fields as typed and trimmed. */
export const PROFILE_URL = '/admin/setup/profile';

type ProfileData = {
    values: Record<BasicField, string | null>;
    logo: Logo;
    cover: Logo;
    map: BusinessMap;
    limits: Pick<Limits, 'logo' | 'cover'>;
    countries: Record<string, string>;
};

/** The step's own words, its group under the setup section's steps. */
export type ProfileStrings = { name: string; help: string; save: string; saving: string; wait: string; error: string };

type ProfileWords = Strings<KeysOf<ProfileStrings>>;

type Action = 'save' | 'logo-upload' | 'logo-remove' | 'cover-upload' | 'cover-remove';

type ImageAction = Exclude<Action, 'save'>;

/** The images' waits, the Settings screen's own. */
const IMAGE_WAITS: Record<ImageAction, SettingsKey> = {
    'logo-upload': 'waits.logo_upload',
    'logo-remove': 'waits.logo_remove',
    'cover-upload': 'waits.cover_upload',
    'cover-remove': 'waits.cover_remove',
};

/** The primary's busy word under each image's write, the one the image's own button shows. */
const IMAGE_BUSY: Record<ImageAction, ShellKey> = {
    'logo-upload': 'shared.image.uploading',
    'logo-remove': 'shared.image.removing',
    'cover-upload': 'shared.image.uploading',
    'cover-remove': 'shared.image.removing',
};

/** Each field the save sends is drawn under its own name. */
const DRAWN: Record<string, BasicField> = Object.fromEntries(BASIC_FIELDS.map((field) => [field, field]));

type Props = SharedProps & { strings: { settings?: SettingsStrings } };

export function ProfileStep() {
    const { data, words } = useStepPage<ProfileData, ProfileStrings>();
    const { props } = usePage<Props>();
    const settings = settingsWordsOf(props.strings, useShell().language);

    return data === null ? null : (
        <SettingsProvider words={settings}>
            <ProfilePanel data={data} words={words} />
        </SettingsProvider>
    );
}

/** An image write's refusal in its sentence, or the image's own where no answer came. */
function plainly(fallback: string): (failure: Failure) => string {
    return (failure) => failure.sentence ?? fallback;
}

/** The primary's busy word under the write in flight: the save's own, or the image's; null while none is. */
function busyWord(inFlight: Action | null, words: ProfileWords, shell: ShellWords): string | null {
    if (inFlight === null) {
        return null;
    }

    return inFlight === 'save' ? words.text('saving') : shell.text(IMAGE_BUSY[inFlight]);
}

function ProfilePanel({ data, words }: { data: ProfileData; words: ProfileWords }) {
    const shell = useShell();
    const settings = useSettings();
    const { saved } = useStepMoves();
    const [seed] = useState(() => seedProfileOf(data.values, BASIC_FIELDS));
    const [form, setForm] = useState(seed);
    const [refused, setRefused] = useState<Partial<Record<BasicField, string>>>({});
    const [logoUploads, setLogoUploads] = useState(0);
    const [coverUploads, setCoverUploads] = useState(0);
    const waits = { save: words.text('wait'), ...Object.fromEntries(Object.entries(IMAGE_WAITS).map(([action, key]) => [action, settings.text(key)])) } as Record<Action, string>;
    const { inFlight, pending, waitFor, visit } = useOneWrite<Action>(waits);
    const checks = profileProblems(form, BASIC_FIELDS, settings);
    const problems = { ...checks, ...refused };
    const reason = BASIC_FIELDS.map((field) => checks[field]).find((sentence) => sentence !== undefined) ?? waitFor('save');
    const sendingImage = inFlight !== null && inFlight !== 'save';

    const set = (field: BasicField) => (value: string) => {
        setForm((current) => ({ ...current, [field]: value }));
        setRefused((current) => forgetting(current, field));
    };

    const save = () =>
        visit(
            'save',
            onField(DRAWN, (field, sentence) => setRefused((current) => ({ ...current, [field]: sentence })), words.text('error')),
            saved,
            (options) => router.put(PROFILE_URL, Object.fromEntries(BASIC_FIELDS.map((field) => [field, form[field].trim()])), options),
        );

    const image = (action: ImageAction, fallback: SettingsKey, send: (options: Record<string, unknown>) => void, landed: () => void) => () => visit(action, plainly(settings.text(fallback)), landed, send);

    useStepPrimary({ label: words.text('save'), onPress: save, blocked: reason, busy: busyWord(inFlight, words, shell) });
    useStepDirty(sendingImage || BASIC_FIELDS.some((field) => form[field] !== seed[field]));

    return (
        <div className="admin-setup-profile flex flex-col gap-4">
            <p className="admin-setup-profile__help text-sm text-muted-foreground">{words.text('help')}</p>
            <Field id="settings-logo" label={settings.text('booking_page.logo')} help={settings.choice(LOGO_HELP, data.limits.logo.megabytes, { n: data.limits.logo.spelled })}>
                {(control) => (
                    <ImageSlot
                        key={logoUploads}
                        block="admin-setup-logo"
                        words={slotWords(LOGO_WORDS, settings)}
                        preview={LOGO_PREVIEW}
                        control={control}
                        image={data.logo}
                        uploading={inFlight === 'logo-upload'}
                        removing={inFlight === 'logo-remove'}
                        wait={pending}
                        onUpload={(file) => image('logo-upload', LOGO_ERROR, (options) => router.post(LOGO_URL, { logo: file }, { ...options, forceFormData: true }), () => setLogoUploads((count) => count + 1))()}
                        onRemove={image('logo-remove', LOGO_ERROR, (options) => router.delete(LOGO_URL, options), () => undefined)}
                    />
                )}
            </Field>
            <ProfileBasics form={form} problems={problems} set={set} map={data.map} countries={data.countries} />
            <Field id="settings-cover" label={settings.text('booking_page.cover')} help={settings.choice(COVER_HELP, data.limits.cover.megabytes, { n: data.limits.cover.spelled })}>
                {(control) => (
                    <ImageSlot
                        key={coverUploads}
                        block="admin-setup-cover"
                        words={slotWords(COVER_WORDS, settings)}
                        preview={COVER_PREVIEW}
                        control={control}
                        image={data.cover}
                        uploading={inFlight === 'cover-upload'}
                        removing={inFlight === 'cover-remove'}
                        wait={pending}
                        onUpload={(file) => image('cover-upload', COVER_ERROR, (options) => router.post(COVER_URL, { cover: file }, { ...options, forceFormData: true }), () => setCoverUploads((count) => count + 1))()}
                        onRemove={image('cover-remove', COVER_ERROR, (options) => router.delete(COVER_URL, options), () => undefined)}
                    />
                )}
            </Field>
        </div>
    );
}
