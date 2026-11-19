import { act, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { KEYED_SETUP, KEYED_STAFF, keyedSetup, keyedStaff } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { Setup, StepKey } from '../model';
import { SetupWizard } from '../SetupWizard';
import { TEAM_URL, type TeamStepStrings } from './TeamStep';

/*
 * The Team step, open in the wizard: the team members in a short list with the services each
 * performs, a short add form of a name and the services ticked from every service the business
 * has, in the Staff screen's words, and a line linking that screen for anything else. The add
 * posts the screen's create by a visit that keeps the page and blanks the form once it lands; a
 * refusal naming a drawn field is said on it, one naming none in an error toast. While the step is
 * open with no member the footer offers Skip for now and no primary; once a member exists, or the
 * step was skipped, the wizard's Next; and while the add is in flight a busy primary. Once the add
 * has landed the focus is on the name. A name typed or a service ticked is asked about before it
 * is left.
 */

const inertia = vi.hoisted(() => ({ post: vi.fn(), listeners: new Map<string, (event: Event) => void>(), setup: undefined as Setup | undefined }));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
    usePage: () => ({ props: { strings: { staff: KEYED_STAFF }, tenant: { currency: 'EUR' }, settings: { granularity: 5 }, setup: inertia.setup } }),
    Link: ({ href, className, children }: { href: string; className?: string; children: ReactNode }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const words = keyedSetup();
const staff = keyedStaff();
const step = KEYED_SETUP.steps.team as TeamStepStrings;

type Member = { id: number; name: string; service_ids: number[] };

type Visit = { preserveState?: boolean; preserveScroll?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

const SERVICES = [
    { id: 4, name: 'Haircut', status: 'active' },
    { id: 7, name: 'Colour', status: 'active' },
    { id: 9, name: 'Perm', status: 'inactive' },
];

let view: Mounted;
let onStep: Mock<(step: StepKey) => void>;
let onClose: Mock<() => void>;

/** The wizard on Team, the page carrying the same setup the wizard is drawn from, as Home does. */
function open(members: Member[], services = SERVICES, skipped = false) {
    const setup: Setup = {
        steps: [
            { key: 'profile', done: true, skipped: false },
            { key: 'team', done: members.length > 0, skipped },
            { key: 'link', done: false, skipped: false },
        ],
        opened: true,
        opens: null,
        data: { team: { members, services } },
    };

    inertia.setup = setup;
    view.render(
        <ToastProvider>
            <SetupWizard setup={setup} table={KEYED_SETUP} words={words} step="team" onStep={onStep} onClose={onClose} />
        </ToastProvider>,
    );
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.listeners.clear();
    inertia.setup = undefined;
    onStep = vi.fn<(step: StepKey) => void>();
    onClose = vi.fn<() => void>();
    view = mount();
});

afterEach(() => {
    view.unmount();
});

const q = (selector: string) => document.body.querySelector(selector);

const all = (selector: string) => [...document.body.querySelectorAll(selector)].map((element) => element.textContent);

/** Sets a text field's value as a person types it, through React's own value setter. */
function typeInto(selector: string, text: string): void {
    const field = q(selector) as HTMLInputElement;

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(field, text);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function refuse(sentence: string, field: string | null): void {
    const data = field === null ? { detail: sentence } : { detail: sentence, field };

    act(() => inertia.listeners.get('invalid')?.(new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 400, data: { code: 'invalid_request', data } } } })));
}

describe('the Team step', () => {
    it('says no member exists yet, offers Skip for now and no primary, and draws every service to tick', () => {
        open([]);

        expect(q('.admin-setup-team__help')?.textContent).toBe(step.help);
        expect(q('.admin-setup-team__empty')?.textContent).toBe(step.empty);
        expect(q('.admin-setup__skip')?.textContent).toBe(words.text('skip'));
        expect(q('.admin-setup__primary')).toBeNull();
        expect(q('label[for="staff-name"]')?.textContent).toBe(staff.text('form.name'));
        expect(q('.admin-setup-team__pick legend')?.textContent).toBe(staff.text('form.services'));
        expect(all('.admin-staff-services__name')).toEqual(['Haircut', 'Colour', staff.text('form.inactive_service', { service: 'Perm' })]);
        // Each tick is the check field's box.
        expect(['size-5', 'accent-primary', 'focus-ring-field'].every((look) => q('.admin-staff-services__tick')?.classList.contains(look))).toBe(true);
    });

    it('lists each member with the services they perform, offers Next once one exists, and links the Staff screen', () => {
        open([
            { id: 2, name: 'Maria', service_ids: [7, 4] },
            { id: 3, name: 'Nikos', service_ids: [] },
        ]);

        expect(all('.admin-setup-team__name')).toEqual(['Maria', 'Nikos']);
        expect(all('.admin-setup-team__services')).toEqual(['Haircut, Colour', step.no_services]);
        expect(q('.admin-setup__skip')).toBeNull();
        expect(q('.admin-setup__primary')?.textContent).toBe(words.text('next'));

        const link = q('.admin-setup-team__open');
        expect([link?.textContent, link?.getAttribute('href')]).toEqual([step.open, '/admin/staff']);

        press(q('.admin-setup__primary'));
        expect(onStep).toHaveBeenCalledWith('link');
    });

    it('offers the wizard\'s Next and no Skip for now on a Team skipped with no member', () => {
        open([], SERVICES, true);

        expect(q('.admin-setup-team__empty')?.textContent).toBe(step.empty);
        expect(q('.admin-setup__skip')).toBeNull();
        expect(q('.admin-setup__primary')?.textContent).toBe(words.text('next'));

        press(q('.admin-setup__primary'));
        expect(onStep).toHaveBeenCalledWith('link');
    });

    it('says no service exists where the business has none, and holds Add until the member has a name', () => {
        open([], []);

        expect(q('.admin-staff-services__none')?.textContent).toBe(staff.text('form.no_services'));
        expect((q('.admin-setup-team__add') as HTMLButtonElement).disabled).toBe(true);
        expect(q('.admin-setup-team__reason')?.textContent).toBe(staff.text('form.needs_name'));
    });

    it('adds the member with the services ticked as the Staff screen creates one, busy until it lands, then blanks the form', () => {
        open([]);
        typeInto('#staff-name', '  Maria ');
        press(q('#staff-service-7'));
        press(q('#staff-service-9'));
        press(q('#staff-service-9'));

        press(q('.admin-setup-team__add'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, visit] = inertia.post.mock.lastCall as [string, unknown, Visit];
        expect([url, body]).toEqual([TEAM_URL, { name: 'Maria', email: null, user_id: null, status: 'active', online: true, title: null, bio: null, service_ids: [7] }]);
        expect(visit).toMatchObject({ preserveState: true, preserveScroll: true });

        act(() => visit.onStart?.());
        expect(q('.admin-setup-team__add')?.textContent).toBe(step.adding);
        expect(q('.admin-setup__primary')?.textContent).toBe(step.adding);

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect((q('#staff-name') as HTMLInputElement).value).toBe('');
        expect((q('#staff-service-7') as HTMLInputElement).checked).toBe(false);
        expect(onStep).not.toHaveBeenCalled();
    });

    it('puts the focus on the name once the add has landed and the form is blank', () => {
        open([]);
        typeInto('#staff-name', 'Maria');
        (q('.admin-setup-team__add') as HTMLButtonElement).focus();

        press(q('.admin-setup-team__add'));
        const visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        expect(document.activeElement).not.toBe(q('#staff-name'));

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect((q('#staff-name') as HTMLInputElement).value).toBe('');
        expect(document.activeElement).toBe(q('#staff-name'));
    });

    it('asks before a typed name or a ticked service is left, and asks nothing of a blank form or once the add has landed', () => {
        open([]);

        typeInto('#staff-name', 'Maria');
        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard .admin-modal__title')?.textContent).toBe(words.text('discard.title'));
        press(q('.admin-setup-discard__keep'));

        typeInto('#staff-name', '');
        press(q('#staff-service-7'));
        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard')).not.toBeNull();
        press(q('.admin-setup-discard__keep'));
        expect(onClose).not.toHaveBeenCalled();

        typeInto('#staff-name', 'Maria');
        press(q('.admin-setup-team__add'));
        const visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });

        press(q('.admin-setup__close'));
        expect(q('.admin-setup-discard')).toBeNull();
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('adds a member who performs no service', () => {
        open([]);
        typeInto('#staff-name', 'Nikos');

        press(q('.admin-setup-team__add'));

        expect(inertia.post.mock.lastCall?.[1]).toMatchObject({ name: 'Nikos', service_ids: [] });
    });

    it('says a refused name or service on its field until it is edited, and a refusal naming no drawn field in an error toast', () => {
        open([]);
        typeInto('#staff-name', 'Maria');
        press(q('#staff-service-4'));

        press(q('.admin-setup-team__add'));
        let visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        refuse('That service is gone.', 'service_ids');
        act(() => visit.onFinish?.());
        expect(q('#staff-services-error')?.textContent).toBe('That service is gone.');
        // The refusal is drawn as a field's error: 13.5px behind the alert icon.
        expect(q('#staff-services-error')?.classList.contains('text-label')).toBe(true);
        expect(q('#staff-services-error svg')?.getAttribute('aria-hidden')).toBe('true');

        press(q('#staff-service-4'));
        expect(q('#staff-services-error')).toBeNull();

        press(q('.admin-setup-team__add'));
        visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        refuse('That name is taken.', 'name');
        act(() => visit.onFinish?.());
        expect(q('#staff-name-error')?.textContent).toBe('That name is taken.');

        typeInto('#staff-name', 'Maria K');
        expect(q('#staff-name-error')).toBeNull();

        press(q('.admin-setup-team__add'));
        visit = inertia.post.mock.lastCall?.[2] as Visit;
        act(() => visit.onStart?.());
        refuse('The email is refused.', 'email');
        act(() => visit.onFinish?.());
        expect(all('.admin-toast[data-kind="error"] p')).toEqual(['The email is refused.']);
    });
});
