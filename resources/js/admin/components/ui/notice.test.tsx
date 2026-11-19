import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { keyedShell } from '@/testing/keyed';
import { mount, press, type Mounted } from './harness';
import { Notice, type NoticeKind } from './notice';

/*
 * The notice: info, warning, error and success; the error notice alone takes the alert role and the
 * rest status; each kind with its icon, so the kind never rests on the colour, on a soft ground of
 * its family; optionally dismissible, removing itself on its control, an icon button named for
 * what it does.
 */

/** Each kind's icon and ground. */
const KINDS: Record<NoticeKind, { icon: string; ground: string }> = {
    info: { icon: 'lucide-info', ground: 'bg-muted' },
    warning: { icon: 'lucide-triangle-alert', ground: 'bg-attention-soft' },
    error: { icon: 'lucide-circle-alert', ground: 'bg-destructive-soft' },
    success: { icon: 'lucide-circle-check', ground: 'bg-positive-soft' },
};

/** A class naming one of the stock palette's colours by its shade, which no admin part paints with. */
const RAW_PALETTE = /(^|[:-])(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d/;

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('the notice', () => {
    it('gives the error notice the alert role and the other three status', () => {
        const roles: Record<string, string | null> = {};

        for (const kind of ['info', 'warning', 'error', 'success'] satisfies NoticeKind[]) {
            view.render(<Notice kind={kind}>Something to say.</Notice>);
            roles[kind] = view.host.querySelector(`.admin-notice--${kind}`)?.getAttribute('role') ?? null;
        }

        expect(roles).toEqual({ info: 'status', warning: 'status', error: 'alert', success: 'status' });
    });

    it('has no dismiss control unless it is dismissible', () => {
        view.render(<Notice kind="info">Something to say.</Notice>);

        expect(view.host.querySelector('.admin-notice__dismiss')).toBeNull();
    });

    it('removes itself on its dismiss control and tells its owner', () => {
        const onDismiss = vi.fn();

        view.render(
            <Notice kind="warning" dismissible onDismiss={onDismiss}>
                Something to say.
            </Notice>,
        );

        const dismiss = view.host.querySelector('.admin-notice__dismiss');

        expect(dismiss?.getAttribute('aria-label')).toBe(keyedShell().text('kit.notice.dismiss'));
        press(dismiss);

        expect(view.host.querySelector('.admin-notice')).toBeNull();
        expect(onDismiss).toHaveBeenCalledOnce();
    });

    it('gives each kind its icon, hidden from assistive technology, on the soft ground of its family', () => {
        for (const [kind, { icon, ground }] of Object.entries(KINDS)) {
            view.render(<Notice kind={kind as NoticeKind}>Something to say.</Notice>);

            const notice = view.host.querySelector('.admin-notice');
            const svgs = [...(notice?.querySelectorAll('.admin-notice__icon svg') ?? [])];

            expect([kind, svgs.length, svgs[0]?.classList.contains(icon)]).toEqual([kind, 1, true]);
            expect(svgs[0]?.getAttribute('aria-hidden')).toBe('true');
            expect([kind, notice?.classList.contains(ground)]).toEqual([kind, true]);
            expect(notice?.classList.contains('rounded-notice')).toBe(true);
            expect(notice?.firstElementChild?.classList.contains('admin-notice__icon')).toBe(true);
        }
    });

    it('sets its words 600 at 14.5px', () => {
        view.render(<Notice kind="info">Something to say.</Notice>);

        const notice = view.host.querySelector('.admin-notice');

        expect(notice?.classList.contains('text-body-sm')).toBe(true);
        expect(notice?.classList.contains('font-semibold')).toBe(true);
    });

    it('breaks a long unbroken word in its body, so an address with no space stays inside the notice', () => {
        view.render(
            <Notice kind="error" dismissible>
                someone-with-a-very-long-address@a-very-long-domain-name.example.com
            </Notice>,
        );

        const body = view.host.querySelector('.admin-notice__body');

        expect(['min-w-0', 'flex-1', 'wrap-anywhere'].filter((name) => !body?.classList.contains(name))).toEqual([]);
    });

    it('paints with the design\'s colours, no class of a raw palette colour left', () => {
        for (const kind of Object.keys(KINDS) as NoticeKind[]) {
            view.render(
                <Notice kind={kind} dismissible>
                    Something to say.
                </Notice>,
            );

            const classes = [view.host.querySelector('.admin-notice'), ...view.host.querySelectorAll('.admin-notice *')].flatMap((element) => [...(element?.classList ?? [])]);

            expect([kind, classes.filter((name) => RAW_PALETTE.test(name))]).toEqual([kind, []]);
        }
    });

    it('makes the dismiss control an icon button that keeps its name', () => {
        view.render(
            <Notice kind="success" dismissible>
                Something to say.
            </Notice>,
        );

        const dismiss = view.host.querySelector('.admin-notice__dismiss');

        expect(dismiss?.tagName).toBe('BUTTON');
        expect(dismiss?.getAttribute('type')).toBe('button');
        expect(dismiss?.classList.contains('admin-button')).toBe(true);
        expect(dismiss?.classList.contains('admin-button--icon-only')).toBe(true);
        expect(dismiss?.classList.contains('admin-button--small')).toBe(true);
        expect(dismiss?.querySelector('svg')?.classList.contains('lucide-x')).toBe(true);
        expect(dismiss?.getAttribute('aria-label')).toBe(keyedShell().text('kit.notice.dismiss'));
    });
});
