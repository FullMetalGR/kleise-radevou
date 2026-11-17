import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press, settle } from '@/components/ui/harness';
import { COPY_BOOKING_LINK } from '@/lib/copy';
import { NOT_DONE } from '@/lib/visits';
import { KEYED_SETUP, keyedSetup, keyedShell } from '@/testing/keyed';
import { ToastProvider } from '@/toast/toasts';
import type { Setup } from '../model';
import { SetupWizard } from '../SetupWizard';
import { FINISH_URL } from './LinkStep';

/*
 * The Booking link step, open in the wizard: the hosted booking page's address as a link to a new
 * tab, Copy, which puts the link on the clipboard and records nothing, Open booking page in a new
 * tab, and Finish as the wizard's primary, which alone records the step, by a post that keeps the
 * page, busy until it lands, and closes the wizard once it has. A Finish the server refused leaves
 * the wizard up and says so in an error toast.
 */

const inertia = vi.hoisted(() => ({ post: vi.fn(), listeners: new Map<string, (event: Event) => void>() }));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: inertia.post,
        on: (name: string, listener: (event: Event) => void) => {
            inertia.listeners.set(name, listener);

            return () => inertia.listeners.delete(name);
        },
    },
}));

const HOSTED = 'http://luna.localhost/';

const words = keyedSetup();
const link = KEYED_SETUP.steps.link as unknown as { help: string; open: string; finish: string; finishing: string };

const SETUP: Setup = {
    steps: [
        { key: 'profile', done: true, skipped: false },
        { key: 'services', done: true, skipped: false },
        { key: 'link', done: false, skipped: false },
    ],
    opened: true,
    opens: null,
    data: { link: { hosted_url: HOSTED } },
};

type Visit = { preserveState?: boolean; preserveScroll?: boolean; async?: boolean; onStart?: () => void; onSuccess?: () => void; onFinish?: () => void };

function Holder() {
    const [up, setUp] = useState(true);

    return up ? <SetupWizard setup={SETUP} table={KEYED_SETUP} words={words} step="link" onStep={() => {}} onClose={() => setUp(false)} /> : <p className="closed" />;
}

let view: Mounted;

beforeEach(() => {
    inertia.post.mockReset();
    inertia.listeners.clear();
    view = mount();
    view.render(
        <ToastProvider>
            <Holder />
        </ToastProvider>,
    );
});

afterEach(() => {
    view.unmount();
});

const q = (selector: string) => document.body.querySelector(selector);

function lastPost(): [string, unknown, Visit] {
    return inertia.post.mock.lastCall as [string, unknown, Visit];
}

describe('the Booking link step', () => {
    it('shows the hosted page\'s address as a link to a new tab, Copy, Open booking page in a new tab, and Finish as the primary', () => {
        expect(q('.admin-setup-link__help')?.textContent).toBe(link.help);

        const address = q('.admin-setup-link__url');
        expect([address?.textContent, address?.getAttribute('href'), address?.getAttribute('target'), address?.getAttribute('rel')]).toEqual([HOSTED, HOSTED, '_blank', 'noreferrer']);

        expect(q('.admin-setup-link__copy')?.textContent).toBe(keyedShell().text(COPY_BOOKING_LINK));

        const open = q('.admin-setup-link__open');
        expect([open?.textContent, open?.getAttribute('href'), open?.getAttribute('target')]).toEqual([link.open, HOSTED, '_blank']);

        expect(q('.admin-setup__primary')?.textContent).toBe(link.finish);
        expect(q('.admin-setup__skip')).toBeNull();
    });

    it('copies the link to the clipboard and records nothing, the step left open for Finish', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

        press(q('.admin-setup-link__copy'));
        await settle();

        expect(writeText).toHaveBeenCalledWith(HOSTED);
        expect(inertia.post).not.toHaveBeenCalled();
        expect(q('.closed')).toBeNull();
        expect(q('.admin-setup__step[aria-current="step"]')?.getAttribute('data-step')).toBe('link');
    });

    it('finishes by a post that keeps the page, busy until it lands, then closes the wizard', () => {
        press(q('.admin-setup__primary'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, body, visit] = lastPost();
        expect([url, body]).toEqual([FINISH_URL, {}]);
        expect(visit).toMatchObject({ preserveState: true, preserveScroll: true });
        expect(visit.async).toBeUndefined();

        act(() => visit.onStart?.());
        expect(q('.admin-setup__primary')?.textContent).toBe(link.finishing);
        expect(view.host.querySelector('.closed')).toBeNull();

        act(() => {
            visit.onSuccess?.();
            visit.onFinish?.();
        });
        expect(view.host.querySelector('.closed')).not.toBeNull();
    });

    it('leaves the wizard up and says a refused Finish in an error toast, the button free again', () => {
        press(q('.admin-setup__primary'));
        const [, , visit] = lastPost();

        act(() => visit.onStart?.());
        act(() => inertia.listeners.get('exception')?.(new Event('exception', { cancelable: true })));
        act(() => visit.onFinish?.());

        expect(view.host.querySelector('.closed')).toBeNull();
        expect(q('.admin-setup__primary')?.textContent).toBe(link.finish);
        expect([...document.body.querySelectorAll('.admin-toast[data-kind="error"] p')].map((line) => line.textContent)).toEqual([keyedShell().text(NOT_DONE)]);
    });
});
