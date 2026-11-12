import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { keyedShell } from '@/testing/keyed';
import { mount, press, type Mounted } from './harness';
import { Notice, type NoticeKind } from './notice';

/*
 * The notice: info, warning, error and success; the error notice alone takes the alert role and the
 * rest status; optionally dismissible, removing itself on its control.
 */

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
});
