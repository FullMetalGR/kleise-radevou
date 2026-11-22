import { act } from 'react';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import { ADMIN_LINES_EN } from '../lines.generated';
import { REACH } from '@/components/ui/button';
import { mount, press, type Mounted } from '@/components/ui/harness';
import { KEYED_TEAM, keyedShell, keyedTeam } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { TeamStrings } from '@/types';
import Team, { type Member } from './Team';

/*
 * The Team screen: the title over the count, Invite someone with Name, Email, the role as a
 * segmented control and the roles' sentence, then Members, one row per user, the viewer marked
 * You and an invitation still out marked as such, the staff row by name or None, the role as a
 * segmented control writing at once, Send sign-in link, and Remove behind the kit's confirm, held
 * on the viewer's own row with its reason. Every write is one visit at a time, the pressed
 * control busy and the others held, and a refusal is the server's sentence in an error toast.
 * Every word is a key of the team section, rendered through the keyed table. The framework is
 * stood in for by a router that records each visit and the failure listeners registered around
 * it.
 */

const words = keyedTeam();

const shell = keyedShell();

type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

type Visit = { onStart?: () => void; onSuccess?: () => void; onFinish?: (ended?: unknown) => void };

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    listeners: new Map<string, (event: Event) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: inertia.props }),
    router: {
        post: inertia.post,
        put: inertia.put,
        delete: inertia.delete,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

/** The four fixture members: the viewer, a manager and a staff member with staff rows, and an invitation out. */
const MEMBERS: Member[] = [
    { id: 1, name: 'Luna Owner', email: 'owner@luna.example', role: 'owner', you: true, invited: false, staff: null },
    { id: 5, name: 'Elena Papadaki', email: 'elena@luna.example', role: 'manager', you: false, invited: false, staff: 'Elena Papadaki' },
    { id: 6, name: 'Nikos Georgiou', email: 'nikos@luna.example', role: 'staff', you: false, invited: false, staff: 'Nikos Georgiou' },
    { id: 9, name: 'Ioanna Lazarou', email: 'ioanna@luna.example', role: 'staff', you: false, invited: true, staff: null },
];

function propsWith(members: Member[] = MEMBERS): Record<string, unknown> {
    return {
        tenant: { name: 'Luna Hair Studio', slug: 'luna', timezone: 'Europe/Athens', currency: 'EUR' },
        auth: { user: { id: 1, name: 'Luna Owner', role: 'owner' } },
        flash: null,
        errors: {},
        permissions: ['viewBookings', 'manageBookings', 'manageSettings', 'manageBilling'],
        entitlements: ['staff'],
        subscription: { state: 'active' },
        settings: { granularity: 15, week_start: 1, date_format: 'd/m/Y', time_format: 'H:i', messages: [] },
        nav: [],
        language: 'en',
        strings: { shell: {}, team: KEYED_TEAM },
        members,
    };
}

function q(selector: string): Element | null {
    return document.querySelector(selector);
}

function texts(selector: string): (string | null)[] {
    return [...document.querySelectorAll(selector)].map((element) => element.textContent);
}

/** Types into an input as a person does, through React's own value setter. */
function type(selector: string, value: string): void {
    const input = q(selector) as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;

    act(() => {
        setter?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

/** The segmented control's options in a scope as [label, pressed]. */
function segments(selector: string): [string | null, string | null][] {
    return [...document.querySelectorAll(`${selector} button`)].map((button) => [button.textContent, button.getAttribute('aria-pressed')]);
}

/** A segment of a segmented control by its label. */
function segment(selector: string, label: string): Element | null {
    return [...document.querySelectorAll(`${selector} button`)].find((button) => button.textContent === label) ?? null;
}

function row(index: number): string {
    return `.admin-team-members tbody tr:nth-child(${String(index + 1)})`;
}

function visitOf(mock: ReturnType<typeof vi.fn>, at: number): Visit {
    return (mock.mock.lastCall?.[at] ?? {}) as Visit;
}

/** The visit in flight is refused with an envelope, as the framework reports one the page owns. */
function refuse(detail: string): void {
    const event = new CustomEvent('invalid', { cancelable: true, detail: { response: { status: 409, data: { code: 'last_owner', message: 'last_owner', data: { status: 409, detail } } } } });

    act(() => inertia.listeners.get('invalid')?.(event));
}

function Page() {
    return (
        <ToastProvider>
            <Team />
        </ToastProvider>
    );
}

let view: Mounted;

beforeEach(() => {
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.delete.mockReset();
    inertia.listeners.clear();
    inertia.props = propsWith();
    view = mount();
});

afterEach(() => view.unmount());

describe('the team section\'s type', () => {
    it('is the keys of lang/en/admin/team.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.team>>().toEqualTypeOf<TeamStrings>();
        expect(Object.keys(ADMIN_LINES_EN.team)).toEqual(['page', 'invite', 'roles', 'members', 'wait', 'errors', 'validation', 'refusals', 'flashes']);
    });
});

describe('the Team screen', () => {
    it('heads the page with its title and the count of the team, read as a choice', () => {
        view.render(<Page />);

        expect(q('.admin-team-screen h1')?.textContent).toBe(words.text('page.title'));
        expect(q('.admin-team-count')?.textContent).toBe(words.choice('page.count', 4));
    });

    it('offers Invite someone: Name, Email, the role as Owner, Manager and Staff with Staff chosen, Send invitation and the roles\' sentence', () => {
        view.render(<Page />);

        expect(q('.admin-team-invite h2')?.textContent).toBe(words.text('invite.title'));
        expect(texts('.admin-team-invite label')).toEqual([words.text('invite.name'), words.text('invite.email')]);
        expect(q('.admin-team-invite__role-label')?.textContent).toBe(words.text('invite.role'));
        expect(segments('.admin-team-invite__role')).toEqual([
            [words.text('roles.owner'), 'false'],
            [words.text('roles.manager'), 'false'],
            [words.text('roles.staff'), 'true'],
        ]);
        expect(q('.admin-team-invite__send')?.textContent).toBe(words.text('invite.send'));
        expect(q('.admin-team-invite__help')?.textContent).toBe(words.text('invite.roles_help'));
    });

    it('sends the invitation with the name, the address and the role chosen, busy while it is in flight, and clears the form once it lands', () => {
        view.render(<Page />);

        type('#team-invite-name', 'Maria Ioannou');
        type('#team-invite-email', 'maria@luna.example');
        press(segment('.admin-team-invite__role', words.text('roles.manager')));
        press(q('.admin-team-invite__send'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post.mock.lastCall?.slice(0, 2)).toEqual(['/admin/team', { name: 'Maria Ioannou', email: 'maria@luna.example', role: 'manager' }]);

        act(() => visitOf(inertia.post, 2).onStart?.());
        expect(q('.admin-team-invite__send')?.getAttribute('aria-busy')).toBe('true');
        expect(q('.admin-team-invite__send')?.textContent).toContain(words.text('invite.sending'));

        act(() => {
            visitOf(inertia.post, 2).onSuccess?.();
            visitOf(inertia.post, 2).onFinish?.();
        });
        expect((q('#team-invite-name') as HTMLInputElement).value).toBe('');
        expect((q('#team-invite-email') as HTMLInputElement).value).toBe('');
    });

    it('lists the members inside the table frame under Members, Name, Email, Staff row, Role and Actions', () => {
        view.render(<Page />);

        expect(q('.admin-team-members h2')?.textContent).toBe(words.text('members.title'));
        expect(q('.admin-team-members .admin-table-scroll table')).not.toBeNull();
        expect(texts('.admin-team-members thead th')).toEqual([words.text('members.name'), words.text('members.email'), words.text('members.staff_row'), words.text('members.role'), words.text('members.actions')]);
        expect(texts('.admin-team-members tbody tr td:nth-child(2) .admin-table__value')).toEqual(['owner@luna.example', 'elena@luna.example', 'nikos@luna.example', 'ioanna@luna.example']);
    });

    it('marks the viewer You and an invitation still out, and names each staff row or None', () => {
        view.render(<Page />);

        expect(texts(`${row(0)} th:first-child .admin-chip`)).toEqual([words.text('members.you')]);
        expect(texts(`${row(1)} th:first-child .admin-chip`)).toEqual([]);
        expect(texts(`${row(3)} th:first-child .admin-chip`)).toEqual([words.text('members.invited')]);
        expect(q(`${row(3)} .admin-chip`)?.classList.contains('admin-chip--provisional')).toBe(true);
        expect(q(`${row(0)} .admin-chip`)?.classList.contains('admin-chip--past')).toBe(true);
        expect(texts('.admin-team-members tbody tr td:nth-child(3) .admin-table__value')).toEqual([words.text('members.no_staff'), 'Elena Papadaki', 'Nikos Georgiou', words.text('members.no_staff')]);
    });

    it('shows each role as the segmented control named for its member, and writes a change at once', () => {
        view.render(<Page />);

        expect(q(`${row(1)} [role="group"]`)?.getAttribute('aria-label')).toBe(words.text('members.role_of', { name: 'Elena Papadaki' }));
        expect(segments(`${row(1)} td:nth-child(4)`)).toEqual([
            [words.text('roles.owner'), 'false'],
            [words.text('roles.manager'), 'true'],
            [words.text('roles.staff'), 'false'],
        ]);

        press(segment(`${row(1)} td:nth-child(4)`, words.text('roles.staff')));

        expect(inertia.put.mock.lastCall?.slice(0, 2)).toEqual(['/admin/team/5', { role: 'staff' }]);
    });

    it('writes nothing when the role pressed is the one the member holds', () => {
        view.render(<Page />);

        press(segment(`${row(2)} td:nth-child(4)`, words.text('roles.staff')));

        expect(inertia.put).not.toHaveBeenCalled();
    });

    it('sends a member the sign-in link again', () => {
        view.render(<Page />);

        press(q(`${row(3)} .admin-team-link`));

        expect(inertia.post.mock.lastCall?.slice(0, 2)).toEqual(['/admin/team/9/link', {}]);
    });

    it('holds Remove on the viewer\'s own row with its reason, and asks before removing anyone else', () => {
        view.render(<Page />);

        const own = q(`${row(0)} .admin-team-remove`) as HTMLButtonElement;
        expect(own.disabled).toBe(true);
        expect(document.getElementById(own.getAttribute('aria-describedby') ?? '')?.textContent).toBe(words.text('members.remove_self'));

        press(q(`${row(1)} .admin-team-remove`));
        expect(q('.admin-team-remove-confirm')?.textContent).toContain(words.text('members.remove_body', { name: 'Elena Papadaki' }));
        expect(inertia.delete).not.toHaveBeenCalled();

        press(q('.admin-team-remove-confirm__confirm'));
        expect(inertia.delete.mock.lastCall?.[0]).toBe('/admin/team/5');
    });

    it('keeps the kit\'s Keep it on the confirm, which removes nobody', () => {
        view.render(<Page />);

        press(q(`${row(2)} .admin-team-remove`));
        expect(q('.admin-team-remove-confirm__keep')?.textContent).toBe(shell.text('kit.confirm.keep'));
        press(q('.admin-team-remove-confirm__keep'));

        expect(q('.admin-team-remove-confirm')).toBeNull();
        expect(inertia.delete).not.toHaveBeenCalled();
    });

    it('holds every other write while one is in flight, with the wait\'s sentence', () => {
        view.render(<Page />);

        press(q(`${row(1)} .admin-team-link`));
        act(() => visitOf(inertia.post, 2).onStart?.());

        expect((q(`${row(2)} .admin-team-link`) as HTMLButtonElement).disabled).toBe(true);
        expect((q('.admin-team-invite__send') as HTMLButtonElement).disabled).toBe(true);
        expect(q('.admin-team-wait')?.textContent).toBe(words.text('wait'));
    });

    it('says a refusal in the server\'s sentence, in an error toast', () => {
        view.render(<Page />);

        press(segment(`${row(0)} td:nth-child(4)`, words.text('roles.manager')));
        act(() => visitOf(inertia.put, 2).onStart?.());
        refuse('The business needs at least one owner.');
        act(() => visitOf(inertia.put, 2).onFinish?.());

        expect(q('.admin-toast')?.textContent).toContain('The business needs at least one owner.');
    });
});

/** An element's classes as a list, empty where nothing matches. */
function classesOf(selector: string): string[] {
    return [...(q(selector)?.classList ?? [])];
}

describe('the Team screen\'s layout', () => {
    it('lays the screen out by its own width', () => {
        view.render(<Page />);

        expect(classesOf('.admin-team-screen')).toContain('@container');
    });

    it('puts the count of the team on the header\'s lead line, and nothing beside the title', () => {
        view.render(<Page />);

        expect(q('.admin-page-header__lead .admin-team-count')?.textContent).toBe(words.choice('page.count', 4));
        expect(q('.admin-page-header__count')).toBeNull();
    });

    it('draws Invite someone as a form card and Members as a flush card, neither spelled by hand', () => {
        view.render(<Page />);

        expect(classesOf('.admin-team-invite')).toEqual(expect.arrayContaining(['bg-card', 'rounded-tile', 'shadow-card', 'px-6.5', 'pt-6', 'pb-6.5']));
        expect(classesOf('.admin-team-members')).toEqual(expect.arrayContaining(['bg-card', 'rounded-tile', 'shadow-card', 'overflow-clip']));

        for (const card of ['.admin-team-invite', '.admin-team-members']) {
            expect(q(card)?.tagName).toBe('SECTION');
            expect(classesOf(card).filter((utility) => ['border', 'rounded-xl', 'p-4', 'overflow-hidden'].includes(utility))).toEqual([]);
        }

        expect(classesOf('.admin-team-invite > h2#team-invite-title')).toContain('text-card-title');
        expect(classesOf('.admin-team-members > header > h2#team-members-title')).toContain('text-section-title');
        expect(classesOf('.admin-team-members > header')).not.toContain('border-b');
    });

    it('sets Name, Email, the role and Send as one row that stacks where the card holds less than 42rem between its sides and lies on one wrapping line from there', () => {
        view.render(<Page />);

        const row = q('.admin-team-invite__row');
        const [name, email, role, send] = [...(row?.children ?? [])];

        expect(classesOf('.admin-team-invite')).toContain('@container');
        expect(row?.parentElement?.matches('.admin-team-invite')).toBe(true);
        expect(classesOf('.admin-team-invite__row')).toEqual(expect.arrayContaining(['flex', 'flex-col', '@2xl:flex-row', '@2xl:flex-wrap', '@2xl:items-end']));
        expect(row?.children).toHaveLength(4);
        expect(name?.querySelector('#team-invite-name')).not.toBeNull();
        expect(email?.querySelector('#team-invite-email')).not.toBeNull();
        expect(role?.querySelector('.admin-team-invite__role')).not.toBeNull();
        expect(send?.matches('.admin-team-invite__send')).toBe(true);

        for (const field of [name, email]) {
            expect([...(field?.classList ?? [])]).toEqual(expect.arrayContaining(['@2xl:min-w-48', '@2xl:flex-1']));
        }

        expect([...(role?.classList ?? [])]).toEqual(expect.arrayContaining(['flex', 'flex-col', '@2xl:flex-none']));
        expect([...(send?.classList ?? [])]).toContain('@2xl:ms-auto');
        expect([...(row?.querySelectorAll('*') ?? [])].flatMap((element) => [...element.classList]).filter((utility) => utility.startsWith('desktop:'))).toEqual([]);
    });

    it('stretches the invite\'s role across the stacked card under its label, its segments sharing the row', () => {
        view.render(<Page />);

        expect(classesOf('.admin-team-invite__role')).toContain('*:flex-auto');
        expect(classesOf('.admin-team-invite__role')).not.toContain('w-fit');
        expect(classesOf('.admin-team-invite__role-label')).toEqual(expect.arrayContaining(['text-label', 'text-muted-foreground']));
        expect(q('.admin-team-invite__help')?.classList.contains('admin-field__help')).toBe(true);
    });

    it('keeps on every member\'s row its role control and Remove, and the You and Invitation out chips on theirs', () => {
        view.render(<Page />);

        const members = [...document.querySelectorAll('.admin-team-members .admin-team-member')];

        expect(members).toHaveLength(4);

        for (const member of members) {
            expect(member.querySelector('td .admin-team-role[role="group"]')).not.toBeNull();
            expect(member.querySelector('td .admin-team-remove')).not.toBeNull();
        }

        expect(texts(`${row(0)} .admin-chip--past`)).toEqual([words.text('members.you')]);
        expect(texts(`${row(3)} .admin-chip--provisional`)).toEqual([words.text('members.invited')]);
    });

    it('sets a member\'s role under its column name and across the row where the table reads as rows', () => {
        view.render(<Page />);

        const cell = `${row(1)} td:nth-child(4)`;

        expect(classesOf(cell)).toEqual(expect.arrayContaining(['flex-wrap', '*:last:basis-full']));
        expect(q(`${cell} > :last-child .admin-team-role`)).not.toBeNull();
        expect(q(`${cell} .admin-table__label`)?.textContent).toBe(words.text('members.role'));
        expect(classesOf(`${cell} .admin-team-role`)).toEqual(expect.arrayContaining(['w-full', '*:flex-auto']));
    });

    it('reads the members as rows below the wide breakpoint and wherever their box is narrower than 64rem, the heading keeping the rows\' inset under the same two conditions', () => {
        view.render(<Page />);

        const conditional = (selector: string) => classesOf(selector).filter((utility) => utility.startsWith('max-wide:') || utility.startsWith('@max-'));
        const narrowBox = [...document.querySelectorAll('.admin-team-members, .admin-team-members *')].flatMap((element) => [...element.classList]).filter((utility) => utility.startsWith('@max-2xl:'));

        expect(conditional('.admin-team-members table')).toEqual(['max-wide:block', '@max-5xl:block']);
        expect(classesOf(row(1))).toEqual(expect.arrayContaining(['max-wide:px-[18px]', '@max-5xl:px-[18px]']));
        expect(classesOf('.admin-team-members > header')).toEqual(expect.arrayContaining(['px-6.5', 'pt-5', 'pb-3.5']));
        expect(conditional('.admin-team-members > header')).toEqual(['max-wide:px-4.5', '@max-5xl:px-4.5']);
        expect(narrowBox).toEqual([]);
    });

    it('sets Send sign-in link and Remove as links side by side, the first\'s words free to wrap where the grid is tight, its lines starting at the left edge as Remove under it does', () => {
        view.render(<Page />);

        const link = `${row(1)} .admin-team-link`;
        const remove = `${row(1)} .admin-team-remove`;

        expect(classesOf(link)).toEqual(expect.arrayContaining(['text-primary-deep', 'whitespace-normal', 'shrink', 'text-left']));
        expect(classesOf(link).filter((utility) => ['whitespace-nowrap', 'shrink-0', 'text-center', 'text-right'].includes(utility))).toEqual([]);
        expect(classesOf(remove)).toContain('text-destructive');

        for (const action of [link, remove]) {
            expect(classesOf(action)).toEqual(expect.arrayContaining(['h-auto', 'p-0']));
            expect(classesOf(action)).not.toContain('h-11');
        }

        expect(q(link)?.parentElement?.classList.contains('gap-x-3.5')).toBe(true);
    });

    it('gives Send sign-in link and Remove a touch area 44 pixels tall under every condition the members read as rows by, each its own box in the line, and leaves them bare text in the grid', () => {
        view.render(<Page />);

        // The conditions are read off the table itself, so the tall targets cannot drift from them.
        const conditions = classesOf('.admin-team-members table')
            .filter((utility) => utility.endsWith(':block'))
            .map((utility) => utility.slice(0, -':block'.length));
        expect(conditions).toEqual(['max-wide', '@max-5xl']);
        // No layer of the place's own over the line: only the touch layer every small control shares.
        const layered = (utility: string) => utility.includes('after:') || utility.includes('before:');

        for (const member of [0, 1, 3]) {
            for (const action of [`${row(member)} .admin-team-link`, `${row(member)} .admin-team-remove`]) {
                expect(classesOf(action)).toEqual(expect.arrayContaining(['h-auto', 'p-0', 'items-center']));
                expect(classesOf(action).filter((utility) => utility.includes('min-h-'))).toEqual(conditions.map((condition) => `${condition}:min-h-11`));
                expect(classesOf(action).filter(layered)).toEqual(REACH.split(' ').filter(layered));
            }
        }
    });

    it('says why Remove is held on the viewer\'s row in that row and in no other, for a screen reader alone, and as the button\'s description', () => {
        view.render(<Page />);

        const own = q(`${row(0)} .admin-team-remove`);
        const reason = document.getElementById(own?.getAttribute('aria-describedby') ?? '');
        const said = [...document.querySelectorAll('body *')].filter((element) => element.children.length === 0 && element.textContent === words.text('members.remove_self'));

        expect(reason?.textContent).toBe(words.text('members.remove_self'));
        expect(reason?.closest('tr')).toBe(q(row(0)));
        expect(reason?.closest('td')).toBe(own?.closest('td'));
        expect(reason?.hidden).toBe(false);
        expect(reason?.closest('[hidden], [aria-hidden="true"]')).toBeNull();
        expect([...(reason?.classList ?? [])]).toEqual(['sr-only']);
        expect(said).toHaveLength(1);
        expect(said[0]).toBe(reason);

        for (const other of [1, 2, 3]) {
            expect(q(row(other))?.textContent).not.toContain(words.text('members.remove_self'));
            expect(q(`${row(other)} [id^="team-remove-self-"]`)).toBeNull();
        }
    });
});
