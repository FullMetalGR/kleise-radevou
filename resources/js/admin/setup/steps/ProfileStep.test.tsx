import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { COVER_URL, LOGO_URL } from '@/settings/images';
import { type BasicField, profileSentence } from '@/settings/model';
import { KEYED_SETTINGS, KEYED_SETUP, keyedSettings, keyedSetup, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { Setup, StepKey } from '../model';
import { SetupWizard } from '../SetupWizard';
import { PROFILE_URL, type ProfileStrings } from './ProfileStep';

/*
 * The Business profile step, open in the wizard: the logo, About, the address with the map's line,
 * Phone and the cover, the Settings screen's fields in its words, each seeded from its stored
 * value; Save and continue as the primary, which sends the seven fields alone by a put that keeps
 * the page, busy until it lands, and moves on once it has. A field failing its rule holds the
 * primary with its sentence; a refusal naming a field is said on it, one naming none in an error
 * toast. The logo and the cover go through the Settings screen's own routes. While an image's
 * write is in flight the primary is busy under the image's own word, held with the image's wait
 * beside it, so the wizard holds its other moves, and the close button asks first; both are over
 * once the write ends, landed, refused or lost on the way.
 */

const inertia = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), delete: vi.fn(), listeners: new Map<string, (event: Event) => void>() }));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: inertia.post,
        put: inertia.put,
        delete: inertia.delete,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
    usePage: () => ({ props: { strings: { settings: KEYED_SETTINGS }, tenant: { currency: 'EUR' }, settings: { granularity: 5 } } }),
    Link: ({ href, className, children }: { href: string; className?: string; children: ReactNode }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const words = keyedSetup();
const shell = keyedShell();
const settings = keyedSettings();
const profile = KEYED_SETUP.steps.profile as ProfileStrings;

const STORED: Record<BasicField, string | null> = { about: 'We cut hair.', address_line1: '1 Main Street', address_line2: null, city: null, postal_code: null, country: null, phone: null };

type Visit = { preserveState?: boolean; preserveScroll?: boolean; forceFormData?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

let view: Mounted;
let onStep: Mock<(step: StepKey) => void>;
let onClose: Mock<() => void>;

function open(values: Record<BasicField, string | null>, images: { logo?: { name: string; url: string } | null; cover?: { name: string; url: string } | null } = {}) {
    const setup: Setup = {
        steps: [
            { key: 'profile', done: false, skipped: false },
            { key: 'services', done: false, skipped: false },
            { key: 'link', done: false, skipped: false },
        ],
        opened: true,
        opens: null,
        data: {
            profile: {
                values,
                logo: images.logo ?? null,
                cover: images.cover ?? null,
                map: { state: 'pending', image: null },
                limits: { logo: { megabytes: 2, spelled: 'two' }, cover: { megabytes: 5, spelled: 'five' } },
                countries: { GR: 'Greece', CY: 'Cyprus' },
            },
        },
    };

    view.render(
        <ToastProvider>
            <SetupWizard setup={setup} table={KEYED_SETUP} words={words} step="profile" onStep={onStep} onClose={onClose} />
        </ToastProvider>,
    );
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.delete.mockReset();
    inertia.listeners.clear();
    onStep = vi.fn<(step: StepKey) => void>();
    onClose = vi.fn<() => void>();
    view = mount();
});

afterEach(() => {
    view.unmount();
});

const q = (selector: string) => document.body.querySelector(selector);

function valueAt(selector: string): string | null {
    const field = q(selector);

    return field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement ? field.value : null;
}

/** Sets a text field's value as a person types it, through React's own value setter. */
function typeInto(selector: string, text: string): void {
    const field = q(selector) as HTMLInputElement | HTMLTextAreaElement;
    const prototype = field instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;

    act(() => {
        Object.getOwnPropertyDescriptor(prototype, 'value')?.set?.call(field, text);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function refuse(sentence: string, field: string | null): void {
    const data = field === null ? { detail: sentence } : { detail: sentence, field };

    act(() => inertia.listeners.get('invalid')?.(new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 400, data: { code: 'invalid_request', data } } } })));
}

const toasts = () => [...document.body.querySelectorAll('.admin-toast[data-kind="error"] p')].map((line) => line.textContent);

const primary = () => q('.admin-setup__primary') as HTMLButtonElement;

/** Whether each button of the step list is held. */
const listHeld = () => [...document.body.querySelectorAll('.admin-setup__step')].map((button) => (button as HTMLButtonElement).disabled);

/** Picks a cover and presses its upload; the visit the upload was sent with. */
function uploadCover(): Visit {
    const input = q('.admin-setup-cover__file') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: [new File(['cover'], 'cover.png', { type: 'image/png' })], configurable: true });
    act(() => input.dispatchEvent(new Event('change', { bubbles: true })));
    press(q('.admin-setup-cover-upload'));

    return inertia.post.mock.lastCall?.[2] as Visit;
}

/** The ways an image's write ends: landed, refused by the server, or lost on the way. */
const ENDINGS: [string, (visit: Visit) => void][] = [
    [
        'has landed',
        (visit) =>
            act(() => {
                visit.onSuccess?.();
                visit.onFinish?.();
            }),
    ],
    [
        'was refused',
        (visit) => {
            refuse('The cover is too large.', 'cover');
            act(() => visit.onFinish?.());
        },
    ],
    [
        'was lost on the way',
        (visit) => {
            act(() => inertia.listeners.get('exception')?.(new Event('exception', { cancelable: true })));
            act(() => visit.onFinish?.());
        },
    ],
];

describe('the Business profile step', () => {
    it('draws the logo, About, the address with the map line, Phone and the cover, seeded from the stored values, the country on GR where none is stored', () => {
        open(STORED);

        expect(q('.admin-setup-profile__help')?.textContent).toBe(profile.help);
        expect(q('.admin-setup-logo__none')?.textContent).toBe(settings.text('booking_page.logo_none'));
        expect(['#settings-about', '#settings-address-line1', '#settings-address-line2', '#settings-city', '#settings-postal-code', '#settings-country', '#settings-phone'].map(valueAt)).toEqual(['We cut hair.', '1 Main Street', '', '', '', 'GR', '']);
        expect(q('.admin-settings-map')?.textContent).toBe(settings.text('map.pending'));
        expect(q('.admin-setup-cover__none')?.textContent).toBe(settings.text('booking_page.cover_none'));
        expect(q('#settings-public-email')).toBeNull();
        expect(q('#settings-important-info')).toBeNull();
        expect(q('.admin-setup__primary')?.textContent).toBe(profile.save);
        expect(q('.admin-setup__skip')).toBeNull();
    });

    it('saves the seven fields alone, trimmed, by a put that keeps the page, busy until it lands, then moves on', () => {
        open(STORED);
        typeInto('#settings-city', '  Athens  ');

        press(q('.admin-setup__primary'));

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, body, visit] = inertia.put.mock.lastCall as [string, unknown, Visit];
        expect([url, body]).toEqual([PROFILE_URL, { about: 'We cut hair.', address_line1: '1 Main Street', address_line2: '', city: 'Athens', postal_code: '', country: 'GR', phone: '' }]);
        expect(visit).toMatchObject({ preserveState: true, preserveScroll: true });

        act(() => visit.onStart?.());
        expect(q('.admin-setup__primary')?.textContent).toBe(profile.saving);
        expect(onStep).not.toHaveBeenCalled();

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect(onStep).toHaveBeenCalledWith('services');
    });

    it('saves an empty form, every field blank and the country on GR', () => {
        open({ about: null, address_line1: null, address_line2: null, city: null, postal_code: null, country: null, phone: null });

        press(q('.admin-setup__primary'));

        expect(inertia.put.mock.lastCall?.[1]).toEqual({ about: '', address_line1: '', address_line2: '', city: '', postal_code: '', country: 'GR', phone: '' });
    });

    it('holds the primary with the sentence of a field failing its rule, said on the field too', () => {
        open(STORED);
        typeInto('#settings-phone', '1'.repeat(41));

        const sentence = profileSentence('phone', settings);
        expect(q('#admin-setup-blocked')?.textContent).toBe(sentence);
        expect(q('#settings-phone-error')?.textContent).toBe(sentence);
        expect((q('.admin-setup__primary') as HTMLButtonElement).disabled).toBe(true);

        press(q('.admin-setup__primary'));
        expect(inertia.put).not.toHaveBeenCalled();
    });

    it('says a refusal naming a field on that field until it is edited, and one naming none in an error toast, staying on the step', () => {
        open(STORED);

        press(q('.admin-setup__primary'));
        let visit = inertia.put.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        refuse('That phone is refused.', 'phone');
        act(() => visit.onFinish?.());

        expect(q('#settings-phone-error')?.textContent).toBe('That phone is refused.');
        expect(toasts()).toEqual([]);
        expect(onStep).not.toHaveBeenCalled();

        typeInto('#settings-phone', '210 123 4567');
        expect(q('#settings-phone-error')).toBeNull();

        press(q('.admin-setup__primary'));
        visit = inertia.put.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        refuse('Something else went wrong.', null);
        act(() => visit.onFinish?.());

        expect(toasts()).toEqual(['Something else went wrong.']);
        expect(onStep).not.toHaveBeenCalled();
        expect(q('.admin-setup__primary')?.textContent).toBe(profile.save);
    });

    it('removes the logo through the Settings screen\'s own route, the primary held with its wait until it lands', () => {
        open(STORED, { logo: { name: 'logo.png', url: '/images/logo.png' } });

        press(q('.admin-setup-logo-remove'));

        expect(inertia.delete).toHaveBeenCalledTimes(1);
        const [url, visit] = inertia.delete.mock.lastCall as [string, Visit];
        expect(url).toBe(LOGO_URL);

        act(() => visit.onStart?.());
        expect(q('#admin-setup-blocked')?.textContent).toBe(settings.text('waits.logo_remove'));
        expect([primary().textContent, primary().getAttribute('aria-busy'), primary().disabled]).toEqual([shell.text('shared.image.removing'), 'true', true]);
        expect(listHeld()).toEqual([true, true, true]);

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect(q('#admin-setup-blocked')).toBeNull();
        expect(onStep).not.toHaveBeenCalled();
    });

    it('uploads the cover through the Settings screen\'s own route as form data, the image\'s refusal in an error toast', () => {
        open(STORED);
        const input = q('.admin-setup-cover__file') as HTMLInputElement;
        const file = new File(['cover'], 'cover.png', { type: 'image/png' });
        Object.defineProperty(input, 'files', { value: [file], configurable: true });
        act(() => input.dispatchEvent(new Event('change', { bubbles: true })));

        press(q('.admin-setup-cover-upload'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, visit] = inertia.post.mock.lastCall as [string, unknown, Visit];
        expect([url, body, visit.forceFormData]).toEqual([COVER_URL, { cover: file }, true]);

        act(() => visit.onStart?.());
        refuse('The cover is too large.', 'cover');
        act(() => visit.onFinish?.());

        expect(toasts()).toEqual(['The cover is too large.']);
    });

    it.each(ENDINGS)('holds the wizard\'s other moves and asks before closing while the cover is on its way, and neither once the upload %s', (_, end) => {
        open(STORED);

        const visit = uploadCover();
        act(() => visit.onStart?.());

        expect([primary().textContent, primary().getAttribute('aria-busy')]).toEqual([shell.text('shared.image.uploading'), 'true']);
        expect(q('#admin-setup-blocked')?.textContent).toBe(settings.text('waits.cover_upload'));
        expect(listHeld()).toEqual([true, true, true]);

        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard .admin-modal__title')?.textContent).toBe(words.text('discard.title'));
        expect(onClose).not.toHaveBeenCalled();
        press(q('.admin-setup-discard__keep'));

        end(visit);

        expect([primary().textContent, primary().getAttribute('aria-busy'), primary().disabled]).toEqual([profile.save, null, false]);
        expect(q('#admin-setup-blocked')).toBeNull();
        expect(listHeld()).toEqual([false, false, false]);

        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard')).toBeNull();
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('keeps the save\'s own busy word and asks nothing at the close while an unedited form is saved', () => {
        open(STORED);

        press(primary());
        const visit = inertia.put.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());

        expect([primary().textContent, primary().getAttribute('aria-busy')]).toEqual([profile.saving, 'true']);
        expect(q('#admin-setup-blocked')).toBeNull();

        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard')).toBeNull();
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('asks before an edit is left by the step list', () => {
        open(STORED);
        typeInto('#settings-about', 'We cut and colour hair.');

        press([...document.body.querySelectorAll('.admin-setup__step')][1] ?? null);

        expect(q('.admin-setup-discard .admin-modal__title')?.textContent).toBe(words.text('discard.title'));
        expect(onStep).not.toHaveBeenCalled();
    });
});
