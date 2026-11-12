import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { key, mount, type Mounted, press, settle } from '@/components/ui/harness';
import { keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { BookingStatus, PlanName } from './vocabulary';
import Tenant, {
    CLOSE_ENDS_SUSPENSION,
    CLOSE_WARNING,
    CLOSED_ACTIVITY,
    CLOSED_USERS,
    closingSentence,
    FEATURES_NOTE,
    NO_ACTIVITY,
    NO_LAST_ACTIVITY,
    NO_SESSIONS,
    NO_SUBSCRIPTION,
    NO_USERS,
    type OpenSupportSession,
    REOPEN_WARNING,
    RESUME_WARNING,
    SLUG_RELEASED,
    SUPPORT_NOTE,
    TERMS_NOT_RECORDED,
    type TenantDetail,
    type TenantFeatures,
    type TenantLinks,
    termsVersion,
    WAY_BACK_BILLING,
} from './Tenant';
import { NOT_DONE } from '@/lib/visits';

/*
 * A tenant's detail: the facts as the server sent them in four sections, the account, the plan and
 * the subscription, the people and the activity, each vocabulary value in words and every instant
 * as the server spelled it on the console's clock; the close beside the day its retention window
 * ends and the suspension shown only where the row holds them; no counts for a provisioning row;
 * the two links out to the tenant's origin in a new tab and the link to the Audit screen narrowed
 * to the tenant.
 *
 * The suspension's controls, over a recording router: Suspend on an active row alone and Resume on
 * a suspended row alone. Suspend opens a modal with the reason field, posts the reason, shows its
 * busy state and cannot be dismissed until the server's answer lands; a refused reason comes back
 * on the field, and a failure the server could not word is an error toast that stays. Resume opens
 * a confirm naming the tenant and that its booking page reopens, Keep it and Escape posting
 * nothing; its confirm posts no body, shows its busy state and cannot be dismissed until the
 * server's answer lands.
 *
 * The set-password link's control, beside every user whatever the role: it posts no body to that
 * user's link, shows its busy state until the server's answer lands, one send at a time, and the
 * answer arrives as the flash with the redirect back; a failure the server could not word is an
 * error toast that stays.
 *
 * Change features, where the server sends the free tenant's set alone: a checkbox per feature
 * started from the set, Save posting the boxes as one list, busy until the answer lands, one save
 * at a time; a refused list on the boxes with the server's sentence, and a failure the server could
 * not word an error toast that stays.
 *
 * Open as support, on an active or a closing row alone; and the open support sessions, 4.4, on
 * every row, each with End, which posts no body to the session's end, busy on its row until the
 * server's answer lands, one end at a time.
 *
 * The close and the reopen: Close beside Suspend or Resume on an active or a suspended row, its
 * modal holding the reason as Suspend's does and saying on a suspended row that the close ends the
 * suspension, posting the reason, busy and not to be dismissed until the answer lands; Reopen on a
 * closing row in the free shape alone, its confirm saying the account stays open and posting no
 * body. A closing row reads its sentence, since when and the day it is deleted on, and outside the
 * free shape the owner's Billing screen as its way back, nothing offered.
 */

const shell = keyedShell();

type Visit = {
    preserveScroll?: boolean;
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    post: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, className, children }: { href: string; className?: string; children?: unknown }) => (
        <a href={href} className={className}>
            {children as string}
        </a>
    ),
    usePage: () => ({ props: inertia.props }),
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const BOOKINGS: { status: BookingStatus; count: number }[] = [
    { status: 'pending', count: 0 },
    { status: 'awaiting_approval', count: 0 },
    { status: 'awaiting_payment', count: 1 },
    { status: 'confirmed', count: 12 },
    { status: 'completed', count: 30 },
    { status: 'no_show', count: 2 },
    { status: 'cancelled', count: 4 },
    { status: 'rejected', count: 0 },
    { status: 'expired', count: 3 },
];

const LUNA: TenantDetail = {
    slug: 'luna',
    name: 'Luna Hair Studio',
    status: 'active',
    timezone: 'Europe/Athens',
    currency: 'EUR',
    category: 'Hair salon',
    signed_up: 'Tuesday 13 October 2026 at 00:15',
    trial_ends: null,
    grace_ends: 'Wednesday 21 October 2026 at 01:00',
    closing: null,
    closed: null,
    suspension: null,
    terms: null,
    state: 'grace',
    plan: 'staff',
    subscription: { customer: 'cus_luna', status: 'past_due', period_end: 'Sunday 1 November 2026 at 11:00' },
    users: [
        { id: 7, name: 'Alex Owner', email: 'owner@luna.example', role: 'owner', verified: true },
        { id: 12, name: 'Sam Staff', email: 'sam@luna.example', role: 'staff', verified: false },
    ],
    activity: { services: 5, staff: 3, bookings: BOOKINGS, last: 'Tuesday 13 October 2026 at 12:30' },
};

/** The names the server reads from config/billing.php, in the catalogue's order. */
const PLAN_NAMES: PlanName[] = [
    { plan: 'floor', name: 'Solo' },
    { plan: 'staff', name: 'Team' },
];

const LINKS: TenantLinks = { hosted_page: 'http://luna.localhost/', sign_in: 'http://luna.localhost/admin/login', audit: '/audit?tenant=luna' };

function propsWith(tenant: TenantDetail, features: TenantFeatures = null, sessions: OpenSupportSession[] = []): Record<string, unknown> {
    return { flash: null, operator: 'Pat Operator', errors: {}, nav: [], zone: 'Europe/Athens', tenant, plan_names: PLAN_NAMES, links: LINKS, features, support_sessions: sessions };
}

let view: Mounted;

function text(selector: string): string | null | undefined {
    return view.host.querySelector(selector)?.textContent;
}

function texts(selector: string): (string | null)[] {
    return [...view.host.querySelectorAll(selector)].map((element) => element.textContent);
}

function show(tenant: TenantDetail, features: TenantFeatures = null, sessions: OpenSupportSession[] = []): void {
    inertia.props = propsWith(tenant, features, sessions);
    view.render(
        <ToastProvider>
            <Tenant />
        </ToastProvider>,
    );
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.listeners.clear();
    view = mount();
});

afterEach(() => view.unmount());

describe('a tenant\'s detail', () => {
    it('heads the page with the name and the slug, and links out to the tenant\'s origin in a new tab and to its audit', () => {
        show(LUNA);

        expect(text('h1.admin-tenant__name')).toBe('Luna Hair Studio');
        expect(text('.admin-tenant__slug')).toBe('luna');
        expect(view.host.querySelector('.admin-tenant-back')?.getAttribute('href')).toBe('/');

        const hosted = view.host.querySelector('.admin-tenant-links__hosted');
        const signIn = view.host.querySelector('.admin-tenant-links__sign-in');

        expect(hosted?.getAttribute('href')).toBe('http://luna.localhost/');
        expect(hosted?.getAttribute('target')).toBe('_blank');
        expect(hosted?.getAttribute('rel')).toBe('noreferrer');
        expect(signIn?.getAttribute('href')).toBe('http://luna.localhost/admin/login');
        expect(signIn?.getAttribute('target')).toBe('_blank');
        expect(view.host.querySelector('.admin-tenant-links__audit')?.getAttribute('href')).toBe('/audit?tenant=luna');
    });

    it('reads the account in words, the instants as the server spelled them', () => {
        show(LUNA);

        expect(texts('h2')).toEqual(['Support sessions', 'Account', 'Plan and subscription', 'People', 'Activity']);
        expect(text('.admin-tenant__status')).toBe('Active');
        expect(text('.admin-tenant__timezone')).toBe('Europe/Athens');
        expect(text('.admin-tenant__currency')).toBe('EUR');
        expect(text('.admin-tenant__category')).toBe('Hair salon');
        expect(text('.admin-tenant__signed-up')).toBe('Tuesday 13 October 2026 at 00:15');
        expect(view.host.querySelector('.admin-tenant__closing')).toBeNull();
        expect(view.host.querySelector('.admin-tenant__suspended')).toBeNull();
    });

    it('reads the plan, the subscription state, the trial and grace ends and the billing provider\'s facts', () => {
        show(LUNA);

        expect(text('.admin-tenant__plan')).toBe('Team');
        expect(text('.admin-tenant__state')).toBe('Grace');
        expect(text('.admin-tenant__trial-ends')).toBe('None');
        expect(text('.admin-tenant__grace-ends')).toBe('Wednesday 21 October 2026 at 01:00');
        expect(text('.admin-tenant__customer')).toBe('cus_luna');
        expect(text('.admin-tenant__provider-status')).toBe('past_due');
        expect(text('.admin-tenant__period-end')).toBe('Sunday 1 November 2026 at 11:00');
    });

    it('reads floor by the name the server sends, a renamed plan followed', () => {
        show({ ...LUNA, state: 'active', plan: 'floor' });

        expect(text('.admin-tenant__plan')).toBe('Solo');

        view.unmount();
        view = mount();
        inertia.props = { ...propsWith({ ...LUNA, state: 'active', plan: 'floor' }), plan_names: [{ plan: 'floor', name: 'Starter' }] };
        view.render(
            <ToastProvider>
                <Tenant />
            </ToastProvider>,
        );

        expect(text('.admin-tenant__plan')).toBe('Starter');
    });

    it('says there is no subscription with the billing provider where the row has none, and that none has a status or a period yet before the first snapshot', () => {
        show({ ...LUNA, state: 'active', plan: 'free', subscription: null });

        expect(text('.admin-tenant__plan')).toBe('Free');
        expect(text('.admin-tenant__no-subscription')).toBe(NO_SUBSCRIPTION);
        expect(view.host.querySelector('.admin-tenant__customer')).toBeNull();

        view.unmount();
        view = mount();
        show({ ...LUNA, state: 'incomplete', plan: 'unlisted', subscription: { customer: 'cus_new', status: null, period_end: null } });

        expect(text('.admin-tenant__customer')).toBe('cus_new');
        expect(text('.admin-tenant__provider-status')).toBe('None yet');
        expect(text('.admin-tenant__period-end')).toBe('None yet');
    });

    it('reads a closing row as closing since its instant and deleted on its day, in place of the account\'s two closing facts, and outside the free shape names the owner\'s Billing screen as its way back', () => {
        show({ ...LUNA, status: 'closing', state: 'canceled', plan: 'unlisted', closing: { at: 'Monday 5 October 2026 at 11:00', retention_ends: 'Wednesday 4 November 2026 at 10:00', deleted_on: 'Wednesday 4 November 2026' } });

        expect(text('.admin-tenant__status')).toBe('Closing');
        expect(text('.admin-tenant-closing__sentence')).toBe('Closing since Monday 5 October 2026 at 11:00, deleted on Wednesday 4 November 2026.');
        expect(closingSentence({ at: 'Monday 5 October 2026 at 11:00', deleted_on: 'Wednesday 4 November 2026' })).toBe('Closing since Monday 5 October 2026 at 11:00, deleted on Wednesday 4 November 2026.');
        expect(text('.admin-tenant-closing__way-back')).toBe(WAY_BACK_BILLING);
        expect(WAY_BACK_BILLING).toContain('Billing screen');
        expect(view.host.querySelector('.admin-tenant__closing')).toBeNull();
        expect(view.host.querySelector('.admin-tenant__retention-ends')).toBeNull();
        expect(view.host.querySelector('.admin-tenant-reopen')).toBeNull();
    });

    it('reads no closing sentence on a row that is not closing', () => {
        show(LUNA);

        expect(view.host.querySelector('.admin-tenant-closing')).toBeNull();
    });

    it('shows a suspended row\'s instant and reason', () => {
        show({ ...LUNA, status: 'suspended', suspension: { at: 'Wednesday 14 October 2026 at 12:00', reason: 'Chargebacks under review.' } });

        expect(text('.admin-tenant__status')).toBe('Suspended');
        expect(text('.admin-tenant__suspended')).toBe('Wednesday 14 October 2026 at 12:00');
        expect(text('.admin-tenant__suspension-reason')).toBe('Chargebacks under review.');
    });

    it('shows the terms accepted at signup as one fact, the instant and the version\'s day beside it, and "not recorded" where the row holds none', () => {
        show({ ...LUNA, terms: { at: 'instant-token', version: 'day-token' } });

        expect(text('.admin-tenant__terms-at')).toBe('instant-token');
        expect(text('.admin-tenant__terms-version')).toBe(termsVersion('day-token'));
        expect(text('.admin-tenant__terms')).toContain('instant-token');
        expect(text('.admin-tenant__terms')).toContain('day-token');

        show(LUNA);

        expect(text('.admin-tenant__terms')).toBe(TERMS_NOT_RECORDED);
        expect(view.host.querySelector('.admin-tenant__terms-version')).toBeNull();
    });

    it('lists the people with the role in words and whether each email is verified, and says so where there are none', () => {
        show(LUNA);

        expect(texts('.admin-tenant-people th')).toEqual(['Name', 'Email', 'Role', 'Verified', 'Password']);
        expect(texts('.admin-tenant-people__name')).toEqual(['Alex Owner', 'Sam Staff']);
        expect(texts('.admin-tenant-people__email')).toEqual(['owner@luna.example', 'sam@luna.example']);
        expect(texts('.admin-tenant-people__role')).toEqual(['Owner', 'Staff']);
        expect(texts('.admin-tenant-people__verified')).toEqual(['Yes', 'No']);

        view.unmount();
        view = mount();
        show({ ...LUNA, users: [] });

        expect(text('.admin-tenant-people__none')).toBe(NO_USERS);
    });

    it('counts the services, the active staff and the bookings by status in words, with the last activity', () => {
        show(LUNA);

        expect(text('.admin-tenant__services')).toBe('5');
        expect(text('.admin-tenant__staff')).toBe('3');
        expect(text('.admin-tenant__last')).toBe('Tuesday 13 October 2026 at 12:30');
        expect(texts('.admin-tenant-bookings dt')).toEqual((['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'] as const).map((status) => shell.text(`kit.statuses.${status}`)));
        expect(texts('.admin-tenant-bookings dd')).toEqual(['0', '0', '1', '12', '30', '2', '4', '0', '3']);
        expect(view.host.querySelector('.admin-tenant-bookings [data-status="confirmed"]')?.textContent).toBe('12');
    });

    it('says no booking was ever made where there is no last activity', () => {
        show({ ...LUNA, activity: { services: 0, staff: 1, bookings: BOOKINGS.map((group) => ({ ...group, count: 0 })), last: null } });

        expect(text('.admin-tenant__last')).toBe(NO_LAST_ACTIVITY);
    });

    it('reads a provisioning row with no plan and no counts, saying why', () => {
        show({ ...LUNA, status: 'provisioning', state: 'incomplete', plan: null, subscription: null, grace_ends: null, activity: null });

        expect(text('.admin-tenant__status')).toBe('Provisioning');
        expect(text('.admin-tenant__plan')).toBe('None yet');
        expect(text('.admin-tenant__no-activity')).toBe(NO_ACTIVITY);
        expect(view.host.querySelector('.admin-tenant__services')).toBeNull();
        expect(view.host.querySelector('.admin-tenant-bookings')).toBeNull();
    });

    it('reads a row with no category as none', () => {
        show({ ...LUNA, category: null });

        expect(text('.admin-tenant__category')).toBe('None');
    });
});

const one = (selector: string) => document.body.querySelector(selector);

function lastVisit(): [string, Record<string, unknown>, Visit] {
    const call = inertia.post.mock.lastCall;

    if (call === undefined) {
        throw new Error('No visit was made.');
    }

    return call as [string, Record<string, unknown>, Visit];
}

function typeReason(text: string): void {
    const box = one('.admin-suspend-prompt__reason');

    if (!(box instanceof HTMLTextAreaElement)) {
        throw new Error('No reason box.');
    }

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set?.call(box, text);
        box.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

const SUSPENDED: TenantDetail = { ...LUNA, status: 'suspended', suspension: { at: 'Wednesday 14 October 2026 at 12:00', reason: 'Chargebacks under review.' } };

describe('the suspension\'s controls', () => {
    it.each([
        ['active', LUNA, ['Suspend', 'Close']],
        ['suspended', SUSPENDED, ['Resume', 'Close']],
        ['closing', { ...LUNA, status: 'closing', closing: { at: 'Monday 5 October 2026 at 11:00', retention_ends: 'Wednesday 4 November 2026 at 10:00', deleted_on: 'Wednesday 4 November 2026' } }, []],
        ['closing free', { ...LUNA, status: 'closing', state: 'active', plan: 'free', subscription: null, closing: { at: 'Monday 5 October 2026 at 11:00', retention_ends: 'Wednesday 4 November 2026 at 10:00', deleted_on: 'Wednesday 4 November 2026' } }, ['Reopen']],
        ['provisioning', { ...LUNA, status: 'provisioning', plan: null, activity: null }, []],
    ] as const)('offers on a %s row exactly %j', (_status, tenant, controls) => {
        show(tenant as TenantDetail);

        expect(texts('.admin-tenant-controls button')).toEqual([...controls]);
        expect(view.host.querySelector('.admin-tenant-controls') === null).toBe(controls.length === 0);
    });

    it('opens Suspend on a modal with an empty reason field focused, and Keep it closes it posting nothing', () => {
        show(LUNA);
        press(one('.admin-tenant-suspend'));

        expect(one('.admin-suspend-prompt .admin-modal__title')?.textContent).toBe('Suspend Luna Hair Studio');
        expect((one('.admin-suspend-prompt__reason') as HTMLTextAreaElement).value).toBe('');
        expect(document.activeElement).toBe(one('.admin-suspend-prompt__reason'));

        typeReason('Something typed');
        press(one('.admin-suspend-prompt__keep'));

        expect(one('.admin-suspend-prompt')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();

        press(one('.admin-tenant-suspend'));
        expect((one('.admin-suspend-prompt__reason') as HTMLTextAreaElement).value).toBe('');
    });

    it('posts the reason typed to the tenant\'s suspend, busy and not to be dismissed until the answer lands, and closes on it', () => {
        show(LUNA);
        press(one('.admin-tenant-suspend'));
        typeReason('Chargebacks under review.');
        press(one('.admin-suspend-prompt__confirm'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe('/tenants/luna/suspend');
        expect(body).toEqual({ reason: 'Chargebacks under review.' });
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(one('.admin-suspend-prompt__confirm')?.textContent).toBe('Suspending');
        expect(one('.admin-suspend-prompt__confirm')?.getAttribute('aria-busy')).toBe('true');
        expect((one('.admin-suspend-prompt__keep') as HTMLButtonElement).disabled).toBe(true);
        expect(one('.admin-suspend-prompt__pending')?.textContent).toBe('The suspension has been sent. This closes once the server answers.');

        press(one('.admin-suspend-prompt__keep'));
        expect(one('.admin-suspend-prompt')).not.toBeNull();

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });

        expect(one('.admin-suspend-prompt')).toBeNull();
    });

    it('shows a refused reason on the field with the server\'s sentence and keeps the modal open, and opens clean the next time', () => {
        show(LUNA);
        press(one('.admin-tenant-suspend'));
        press(one('.admin-suspend-prompt__confirm'));

        const [, , visit] = lastVisit();

        act(() => {
            visit.onStart?.();
            visit.onError?.({ reason: 'Give the reason for the suspension. The owners are sent it.' });
            visit.onFinish?.();
        });

        expect(one('.admin-suspend-prompt')).not.toBeNull();
        expect(one('.admin-suspend-prompt .admin-field__error')?.textContent).toBe('Give the reason for the suspension. The owners are sent it.');
        expect(one('.admin-suspend-prompt__reason')?.getAttribute('aria-invalid')).toBe('true');
        expect(one('.admin-suspend-prompt__confirm')?.textContent).toBe('Suspend');

        press(one('.admin-suspend-prompt__keep'));
        press(one('.admin-tenant-suspend'));

        expect(one('.admin-suspend-prompt .admin-field__error')).toBeNull();
    });

    it('says a failure the server could not word in an error toast that stays, keeping the reason typed', () => {
        show(LUNA);
        press(one('.admin-tenant-suspend'));
        typeReason('Chargebacks under review.');
        press(one('.admin-suspend-prompt__confirm'));

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect(texts('.admin-toast[data-kind="error"] p')).toEqual([shell.text(NOT_DONE)]);
        expect((one('.admin-suspend-prompt__reason') as HTMLTextAreaElement).value).toBe('Chargebacks under review.');
    });

    it('opens Resume on a confirm naming the tenant and that its booking page reopens, focus on Keep it, and Keep it or Escape closes it posting nothing', async () => {
        show(SUSPENDED);
        press(one('.admin-tenant-resume'));
        await settle();

        expect(one('.admin-resume-prompt .admin-modal__title')?.textContent).toBe('Resume Luna Hair Studio');
        expect(one('.admin-resume-prompt__warning')?.textContent).toBe(RESUME_WARNING);
        expect(RESUME_WARNING).toContain('booking page');
        expect(document.activeElement).toBe(one('.admin-resume-prompt__keep'));

        press(one('.admin-resume-prompt__keep'));
        expect(one('.admin-resume-prompt')).toBeNull();

        press(one('.admin-tenant-resume'));
        key(one('.admin-resume-prompt'), 'Escape');
        expect(one('.admin-resume-prompt')).toBeNull();

        expect(inertia.post).not.toHaveBeenCalled();
        expect(texts('.admin-tenant-controls > button')).toEqual(['Resume', 'Close']);
    });

    it('posts Resume with no body to the tenant\'s resume from the confirm, busy and not to be dismissed until the answer lands, and closes on it', () => {
        show(SUSPENDED);
        press(one('.admin-tenant-resume'));
        press(one('.admin-resume-prompt__confirm'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe('/tenants/luna/resume');
        expect(body).toEqual({});
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(one('.admin-resume-prompt__confirm')?.textContent).toBe('Resuming');
        expect(one('.admin-resume-prompt__confirm')?.getAttribute('aria-busy')).toBe('true');
        expect(one('.admin-resume-prompt__pending')?.textContent).toBe('The resumption has been sent. This closes once the server answers.');

        press(one('.admin-resume-prompt__keep'));
        key(one('.admin-resume-prompt'), 'Escape');
        press(one('.admin-resume-prompt__confirm'));
        expect(one('.admin-resume-prompt')).not.toBeNull();
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });

        expect(one('.admin-resume-prompt')).toBeNull();
    });

    it('says a failure of Resume the server could not word in an error toast that stays, the confirm left open', () => {
        show(SUSPENDED);
        press(one('.admin-tenant-resume'));
        press(one('.admin-resume-prompt__confirm'));

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect(texts('.admin-toast[data-kind="error"] p')).toEqual([shell.text(NOT_DONE)]);
        expect(one('.admin-resume-prompt__confirm')?.textContent).toBe('Resume');
    });
});

const CLOSING_FREE: TenantDetail = {
    ...LUNA,
    status: 'closing',
    state: 'active',
    plan: 'free',
    subscription: null,
    closing: { at: 'Monday 2 November 2026 at 08:00', retention_ends: 'Wednesday 2 December 2026 at 08:00', deleted_on: 'Wednesday 2 December 2026' },
};

function typeCloseReason(text: string): void {
    const box = one('.admin-close-prompt__reason');

    if (!(box instanceof HTMLTextAreaElement)) {
        throw new Error('No reason box.');
    }

    act(() => {
        Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set?.call(box, text);
        box.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

describe('the close and the reopen', () => {
    it('opens Close on a modal with what a close does and an empty reason field focused, and Keep it closes it posting nothing', () => {
        show(LUNA);
        press(one('.admin-tenant-close'));

        expect(one('.admin-close-prompt .admin-modal__title')?.textContent).toBe('Close Luna Hair Studio');
        expect([...document.body.querySelectorAll('.admin-close-prompt__warning')].map((warning) => warning.textContent)).toEqual([CLOSE_WARNING]);
        expect(CLOSE_WARNING).toContain('thirty days');
        expect(one('.admin-close-prompt__confirm')?.textContent).toBe('Close the account');
        expect((one('.admin-close-prompt__reason') as HTMLTextAreaElement).value).toBe('');
        expect(document.activeElement).toBe(one('.admin-close-prompt__reason'));

        typeCloseReason('Something typed');
        press(one('.admin-close-prompt__keep'));

        expect(one('.admin-close-prompt')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();

        press(one('.admin-tenant-close'));
        expect((one('.admin-close-prompt__reason') as HTMLTextAreaElement).value).toBe('');
    });

    it('says on a suspended row that the close ends the suspension', () => {
        show(SUSPENDED);
        press(one('.admin-tenant-close'));

        expect([...document.body.querySelectorAll('.admin-close-prompt__warning')].map((warning) => warning.textContent)).toEqual([CLOSE_WARNING, CLOSE_ENDS_SUSPENSION]);
        expect(CLOSE_ENDS_SUSPENSION).toContain('ends the suspension');
    });

    it('posts the reason typed to the tenant\'s close, busy and not to be dismissed until the answer lands, and closes on it', () => {
        show(LUNA);
        press(one('.admin-tenant-close'));
        typeCloseReason('The owner asked us to close the account.');
        press(one('.admin-close-prompt__confirm'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe('/tenants/luna/close');
        expect(body).toEqual({ reason: 'The owner asked us to close the account.' });
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(one('.admin-close-prompt__confirm')?.textContent).toBe('Closing');
        expect(one('.admin-close-prompt__confirm')?.getAttribute('aria-busy')).toBe('true');
        expect((one('.admin-close-prompt__keep') as HTMLButtonElement).disabled).toBe(true);
        expect(one('.admin-close-prompt__pending')?.textContent).toBe('The close has been sent. This goes away once the server answers.');

        press(one('.admin-close-prompt__keep'));
        key(one('.admin-close-prompt'), 'Escape');
        press(one('.admin-close-prompt__confirm'));
        expect(one('.admin-close-prompt')).not.toBeNull();
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });

        expect(one('.admin-close-prompt')).toBeNull();
    });

    it('shows a refused reason on the close\'s field with the server\'s sentence and keeps the modal open, and a failure the server could not word in an error toast that stays, the reason kept', () => {
        show(LUNA);
        press(one('.admin-tenant-close'));
        press(one('.admin-close-prompt__confirm'));

        const [, , refused] = lastVisit();

        act(() => {
            refused.onStart?.();
            refused.onError?.({ reason: 'Give the reason for the close. The owners are sent it.' });
            refused.onFinish?.();
        });

        expect(one('.admin-close-prompt')).not.toBeNull();
        expect(one('.admin-close-prompt .admin-field__error')?.textContent).toBe('Give the reason for the close. The owners are sent it.');
        expect(one('.admin-close-prompt__reason')?.getAttribute('aria-invalid')).toBe('true');

        typeCloseReason('Asked to close.');
        press(one('.admin-close-prompt__confirm'));

        const [, , failed] = lastVisit();

        act(() => failed.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => failed.onFinish?.());

        expect(texts('.admin-toast[data-kind="error"] p')).toEqual([shell.text(NOT_DONE)]);
        expect((one('.admin-close-prompt__reason') as HTMLTextAreaElement).value).toBe('Asked to close.');
    });

    it('reads a closing free row\'s sentence with Reopen and no Billing way back', () => {
        show(CLOSING_FREE);

        expect(text('.admin-tenant-closing__sentence')).toBe('Closing since Monday 2 November 2026 at 08:00, deleted on Wednesday 2 December 2026.');
        expect(view.host.querySelector('.admin-tenant-closing__way-back')).toBeNull();
        expect(texts('.admin-tenant-controls > button')).toEqual(['Reopen']);
    });

    it('opens Reopen on a confirm naming the tenant and that the account stays open, focus on Keep it, and Keep it or Escape closes it posting nothing', async () => {
        show(CLOSING_FREE);
        press(one('.admin-tenant-reopen'));
        await settle();

        expect(one('.admin-reopen-prompt .admin-modal__title')?.textContent).toBe('Reopen Luna Hair Studio');
        expect(one('.admin-reopen-prompt__warning')?.textContent).toBe(REOPEN_WARNING);
        expect(REOPEN_WARNING).toContain('stays open');
        expect(document.activeElement).toBe(one('.admin-reopen-prompt__keep'));

        press(one('.admin-reopen-prompt__keep'));
        expect(one('.admin-reopen-prompt')).toBeNull();

        press(one('.admin-tenant-reopen'));
        key(one('.admin-reopen-prompt'), 'Escape');
        expect(one('.admin-reopen-prompt')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('posts Reopen with no body to the tenant\'s reopen from the confirm, busy and not to be dismissed until the answer lands, and says a failure the server could not word in an error toast that stays', () => {
        show(CLOSING_FREE);
        press(one('.admin-tenant-reopen'));
        press(one('.admin-reopen-prompt__confirm'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe('/tenants/luna/reopen');
        expect(body).toEqual({});
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(one('.admin-reopen-prompt__confirm')?.textContent).toBe('Reopening');
        expect(one('.admin-reopen-prompt__confirm')?.getAttribute('aria-busy')).toBe('true');
        expect(one('.admin-reopen-prompt__pending')?.textContent).toBe('The reopen has been sent. This closes once the server answers.');

        press(one('.admin-reopen-prompt__keep'));
        press(one('.admin-reopen-prompt__confirm'));
        expect(one('.admin-reopen-prompt')).not.toBeNull();
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect(texts('.admin-toast[data-kind="error"] p')).toEqual([shell.text(NOT_DONE)]);
    });
});

describe('the set-password link\'s control', () => {
    it.each([
        ['active', LUNA],
        ['suspended', SUSPENDED],
        ['provisioning', { ...LUNA, status: 'provisioning', plan: null, activity: null }],
    ] as const)('offers a link beside every user, whatever the role, while the row is %s', (_status, tenant) => {
        show(tenant as TenantDetail);

        expect(texts('.admin-tenant-people__password-link')).toEqual(['Send password link', 'Send password link']);
        expect([...view.host.querySelectorAll('.admin-tenant-people__password-link')].map((button) => document.getElementById(button.getAttribute('aria-describedby') ?? '')?.textContent)).toEqual([
            'owner@luna.example',
            'sam@luna.example',
        ]);
    });

    it('posts no body to the user\'s link, busy until the answer lands, one send at a time', () => {
        show(LUNA);
        press(view.host.querySelectorAll('.admin-tenant-people__password-link')[1] ?? null);

        const [path, body, visit] = lastVisit();

        expect(path).toBe('/tenants/luna/users/12/password-link');
        expect(body).toEqual({});
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(texts('.admin-tenant-people__password-link')).toEqual(['Send password link', 'Sending']);
        expect(view.host.querySelectorAll('.admin-tenant-people__password-link')[1]?.getAttribute('aria-busy')).toBe('true');

        press(view.host.querySelectorAll('.admin-tenant-people__password-link')[0] ?? null);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(texts('.admin-tenant-people__password-link')).toEqual(['Send password link', 'Send password link']);

        press(view.host.querySelectorAll('.admin-tenant-people__password-link')[0] ?? null);

        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(lastVisit()[0]).toBe('/tenants/luna/users/7/password-link');
    });

    it('says a failure the server could not word in an error toast that stays', () => {
        show(LUNA);
        press(view.host.querySelector('.admin-tenant-people__password-link'));

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect(texts('.admin-toast[data-kind="error"] p')).toEqual([shell.text(NOT_DONE)]);
        expect(texts('.admin-tenant-people__password-link')).toEqual(['Send password link', 'Send password link']);
    });
});

const FREE: TenantDetail = { ...LUNA, state: 'active', plan: 'free', grace_ends: null, subscription: null };

const STAFF_CHOICE = { value: 'staff', label: 'Staff', help: 'More than one staff member, each with their own hours, services, calendar and approvals.' };

function checkbox(value: string): HTMLInputElement {
    const box = one(`#tenant-feature-${value}`);

    if (!(box instanceof HTMLInputElement)) {
        throw new Error(`No checkbox for ${value}.`);
    }

    return box;
}

describe('Change features', () => {
    it('offers nothing where the server sends no set, the plan being billing\'s', () => {
        show(LUNA);

        expect(one('.admin-tenant-features')).toBeNull();
        expect(one('.admin-tenant-features__save')).toBeNull();
    });

    it('offers one checkbox per feature on a free tenant, started from the set the row holds, its name and what it opens beside it', () => {
        show(FREE, { current: ['staff'], offered: [STAFF_CHOICE] });

        expect(text('.admin-tenant-features__legend')).toBe('Features');
        expect(text('.admin-tenant-features__note')).toBe(FEATURES_NOTE);
        expect(checkbox('staff').checked).toBe(true);
        expect(text('label[for="tenant-feature-staff"]')).toBe('Staff');
        expect(text('#tenant-feature-staff-help')).toBe(STAFF_CHOICE.help);
        expect(text('.admin-tenant-features__save')).toBe('Save features');

        show(FREE, { current: [], offered: [STAFF_CHOICE] });

        expect(checkbox('staff').checked).toBe(false);
    });

    it('posts the boxes as one list to the tenant\'s features, busy until the answer lands, one save at a time', () => {
        show(FREE, { current: ['staff'], offered: [STAFF_CHOICE] });
        press(checkbox('staff'));

        expect(checkbox('staff').checked).toBe(false);

        press(one('.admin-tenant-features__save'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe('/tenants/luna/features');
        expect(body).toEqual({ features: [] });
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(text('.admin-tenant-features__save')).toBe('Saving');
        expect(one('.admin-tenant-features__save')?.getAttribute('aria-busy')).toBe('true');

        press(one('.admin-tenant-features__save'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(text('.admin-tenant-features__save')).toBe('Save features');

        press(checkbox('staff'));
        press(one('.admin-tenant-features__save'));

        expect(lastVisit()[1]).toEqual({ features: ['staff'] });
    });

    it('shows a refused list under the boxes with the server\'s sentence, and clears it on the next save', () => {
        show(FREE, { current: [], offered: [STAFF_CHOICE] });
        press(one('.admin-tenant-features__save'));

        const [, , visit] = lastVisit();

        act(() => {
            visit.onStart?.();
            visit.onError?.({ features: 'Choose the features from the list.' });
            visit.onFinish?.();
        });

        expect(text('#tenant-features-error')).toBe('Choose the features from the list.');
        expect(one('.admin-tenant-features')?.getAttribute('aria-describedby')).toBe('tenant-features-error');

        press(one('.admin-tenant-features__save'));
        act(() => lastVisit()[2].onStart?.());

        expect(one('#tenant-features-error')).toBeNull();
    });

    it('says a failure the server could not word in an error toast that stays, keeping the boxes as they were', () => {
        show(FREE, { current: [], offered: [STAFF_CHOICE] });
        press(checkbox('staff'));
        press(one('.admin-tenant-features__save'));

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect(texts('.admin-toast[data-kind="error"] p')).toEqual([shell.text(NOT_DONE)]);
        expect(checkbox('staff').checked).toBe(true);
    });
});

describe('Open as support', () => {
    const CLOSING: TenantDetail = { ...LUNA, status: 'closing', closing: { at: 'Monday 5 October 2026 at 11:00', retention_ends: 'Wednesday 4 November 2026 at 10:00', deleted_on: 'Wednesday 4 November 2026' } };

    it.each([
        ['active', LUNA, true],
        ['closing', CLOSING, true],
        ['suspended', SUSPENDED, false],
        ['provisioning', { ...LUNA, status: 'provisioning', plan: null, activity: null }, false],
    ] as const)('is offered on a %s row exactly where the row is active or closing', (_status, tenant, offered) => {
        show(tenant as TenantDetail);

        expect(texts('.admin-tenant-support__open')).toEqual(offered ? ['Open as support'] : []);
        expect(view.host.querySelector('.admin-tenant-support__note') === null).toBe(!offered);
    });

    it('says what a support session is beside the control: the tenant\'s own admin, Billing withheld, sixty minutes, every change named in the trail', () => {
        show(LUNA);

        expect(text('.admin-tenant-support__note')).toBe(SUPPORT_NOTE);
        expect(one('.admin-tenant-support__open')?.getAttribute('aria-describedby')).toBe('tenant-support-note');
    });

    it('posts no body to the tenant\'s support, busy until the answer lands, one press at a time', () => {
        show(LUNA);
        press(one('.admin-tenant-support__open'));

        const [path, body, visit] = lastVisit();

        expect(path).toBe('/tenants/luna/support');
        expect(body).toEqual({});
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(one('.admin-tenant-support__open')?.textContent).toBe('Opening');
        expect(one('.admin-tenant-support__open')?.getAttribute('aria-busy')).toBe('true');

        press(one('.admin-tenant-support__open'));
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(one('.admin-tenant-support__open')?.textContent).toBe('Open as support');
    });

    it('says a failure the server could not word in an error toast that stays', () => {
        show(LUNA);
        press(one('.admin-tenant-support__open'));

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect(texts('.admin-toast[data-kind="error"] p')).toEqual([shell.text(NOT_DONE)]);
        expect(one('.admin-tenant-support__open')?.textContent).toBe('Open as support');
    });
});

describe('the open support sessions', () => {
    const SESSIONS: OpenSupportSession[] = [
        { id: 31, operator: 'Pat Operator', opened: 'Monday 2 November 2026 at 07:31', ends: 'Monday 2 November 2026 at 08:31' },
        { id: 34, operator: 'Sam Operator', opened: 'Monday 2 November 2026 at 07:45', ends: 'Monday 2 November 2026 at 08:45' },
    ];

    it('lists each open session with its operator, the opening and the end as the server spelled them, and End beside it', () => {
        show(LUNA, null, SESSIONS);

        expect(text('.admin-tenant-sessions h2')).toBe('Support sessions');
        expect(texts('.admin-tenant-sessions__operator')).toEqual(['Pat Operator', 'Sam Operator']);
        expect(texts('.admin-tenant-sessions__opened')).toEqual(['Monday 2 November 2026 at 07:31', 'Monday 2 November 2026 at 07:45']);
        expect(texts('.admin-tenant-sessions__ends')).toEqual(['Monday 2 November 2026 at 08:31', 'Monday 2 November 2026 at 08:45']);
        expect(texts('.admin-tenant-sessions__end')).toEqual(['End', 'End']);
        expect(view.host.querySelector('.admin-tenant-sessions__none')).toBeNull();
    });

    it('says no session is open where there is none, on any row', () => {
        for (const tenant of [LUNA, SUSPENDED]) {
            show(tenant);

            expect(text('.admin-tenant-sessions__none')).toBe(NO_SESSIONS);
            expect(view.host.querySelector('.admin-tenant-sessions__end')).toBeNull();
        }
    });

    it('posts no body to the session\'s end, busy on that row until the answer lands, one end at a time', () => {
        show(LUNA, null, SESSIONS);
        const ends = () => [...view.host.querySelectorAll('.admin-tenant-sessions__end')];
        press(ends()[1] ?? null);

        const [path, body, visit] = lastVisit();

        expect(path).toBe('/support/34/end');
        expect(body).toEqual({});
        expect(visit.preserveScroll).toBe(true);

        act(() => visit.onStart?.());

        expect(ends().map((button) => button.textContent)).toEqual(['End', 'Ending']);
        expect(ends()[1]?.getAttribute('aria-busy')).toBe('true');
        expect(ends()[1]?.getAttribute('aria-describedby')).toBe('tenant-session-34-operator');

        press(ends()[0] ?? null);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        act(() => visit.onFinish?.());

        expect(ends().map((button) => button.textContent)).toEqual(['End', 'End']);
    });

    it('says a failure the server could not word in an error toast that stays', () => {
        show(LUNA, null, SESSIONS);
        press(one('.admin-tenant-sessions__end'));

        const [, , visit] = lastVisit();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new CustomEvent('exception', { cancelable: true, detail: {} })));
        act(() => visit.onFinish?.());

        expect(texts('.admin-toast[data-kind="error"] p')).toEqual([shell.text(NOT_DONE)]);
    });
});

describe('A closed row', () => {
    /** luna as the drop leaves it: no slug, no users, no schema, its closing and its close kept. */
    const CLOSED: TenantDetail = {
        ...LUNA,
        slug: null,
        status: 'closed',
        state: 'canceled',
        grace_ends: null,
        closing: { at: 'Monday 5 October 2026 at 11:00', retention_ends: 'Wednesday 4 November 2026 at 10:00', deleted_on: 'Wednesday 4 November 2026' },
        closed: 'Wednesday 4 November 2026 at 04:30',
        subscription: { customer: 'cus_luna', status: 'canceled', period_end: 'Sunday 1 November 2026 at 11:00' },
        users: [],
        activity: null,
    };

    beforeEach(() => {
        inertia.props = { ...propsWith(CLOSED), links: null };
        view.render(
            <ToastProvider>
                <Tenant />
            </ToastProvider>,
        );
    });

    it('reads its account with the instant it closed, its slug as released, and its plan and subscription as the row keeps them', () => {
        expect(text('.admin-tenant__name')).toBe('Luna Hair Studio');
        expect(text('.admin-tenant__slug')).toBe(SLUG_RELEASED);
        expect(text('.admin-tenant__status')).toBe('Closed');
        expect(text('.admin-tenant__closing')).toBe('Monday 5 October 2026 at 11:00');
        expect(text('.admin-tenant__retention-ends')).toBe('Wednesday 4 November 2026 at 10:00');
        expect(text('.admin-tenant__closed')).toBe('Wednesday 4 November 2026 at 04:30');
        expect(text('.admin-tenant__state')).toBe('Canceled');
        expect(text('.admin-tenant__customer')).toBe('cus_luna');
    });

    it('says its users and its data went with it', () => {
        expect(text('.admin-tenant-people__none')).toBe(CLOSED_USERS);
        expect(text('.admin-tenant__no-activity')).toBe(CLOSED_ACTIVITY);
    });

    it('offers no link, no control, no support, no session and no features', () => {
        for (const selector of ['.admin-tenant-links', '.admin-tenant-controls', '.admin-tenant-support', '.admin-tenant-sessions', '.admin-tenant-features', '.admin-tenant-people__password-link']) {
            expect(view.host.querySelector(selector)).toBeNull();
        }
    });
});
