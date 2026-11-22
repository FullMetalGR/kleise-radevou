import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Flash } from '../types';
import { KEYED_SHELL, keyedShell, WithShell } from '@/testing/keyed';
import { AUTO_CLEAR_MS, DISMISS, FlashToast, ToastProvider, useToasts } from './toasts';
import { FORCED_EDGE } from '@/components/ui/input';

/*
 * The toasts and the flash consumed into one.
 */

let host: HTMLDivElement;
let root: Root;

beforeEach(() => {
    vi.useFakeTimers();
    host = document.createElement('div');
    document.body.append(host);
    root = createRoot(host);
});

afterEach(() => {
    act(() => root.unmount());
    host.remove();
    vi.useRealTimers();
});

const messages = () => [...host.querySelectorAll('.admin-toast p')].map((paragraph) => paragraph.textContent);

function renderFlash(flash: Flash | null): void {
    act(() =>
        root.render(
            <WithShell>
                <ToastProvider>
                    <FlashToast flash={flash} />
                </ToastProvider>
            </WithShell>,
        ),
    );
}

describe('toasts', () => {
    it('renders a flash as a toast', () => {
        renderFlash({ kind: 'success', message: KEYED_SHELL.flashes.signed_out });

        expect(messages()).toEqual([KEYED_SHELL.flashes.signed_out]);
        expect(host.querySelector('.admin-toast')?.getAttribute('role')).toBe('status');
    });

    it('clears a success toast by itself', () => {
        renderFlash({ kind: 'success', message: 'Saved.' });

        act(() => vi.advanceTimersByTime(AUTO_CLEAR_MS - 1));
        expect(messages()).toEqual(['Saved.']);

        act(() => vi.advanceTimersByTime(1));
        expect(messages()).toEqual([]);
    });

    it('keeps an error toast until it is dismissed', () => {
        renderFlash({ kind: 'error', message: 'That booking could not be cancelled.' });

        act(() => vi.advanceTimersByTime(AUTO_CLEAR_MS * 20));
        expect(messages()).toEqual(['That booking could not be cancelled.']);
        expect(host.querySelector('.admin-toast')?.getAttribute('role')).toBe('alert');
        expect(host.querySelector('.admin-toast-dismiss')?.textContent).toBe(keyedShell().text(DISMISS));

        act(() => host.querySelector<HTMLButtonElement>('.admin-toast-dismiss')?.click());
        expect(messages()).toEqual([]);
    });

    it('draws a toast as an ink message rounded to hold two lines, its dismiss an icon button named Dismiss', () => {
        renderFlash({ kind: 'success', message: 'Saved.' });

        const toast = host.querySelector('.admin-toast');
        const dismiss = host.querySelector('.admin-toast-dismiss');

        expect(toast?.classList.contains('bg-foreground')).toBe(true);
        expect(toast?.classList.contains('text-background')).toBe(true);
        expect(toast?.classList.contains('rounded-inner')).toBe(true);
        expect(dismiss?.tagName).toBe('BUTTON');
        expect(dismiss?.classList.contains('admin-button--icon-only')).toBe(true);
        expect(dismiss?.querySelector('svg.lucide-x')?.getAttribute('aria-hidden')).toBe('true');
        expect(dismiss?.querySelector('.sr-only')?.textContent).toBe(keyedShell().text(DISMISS));
        // The focus outline reads in the message's own ink, since the theme colour can be as dark as the fill.
        expect(dismiss?.classList.contains('focus-ring')).toBe(true);
        expect(dismiss?.classList.contains('focus-visible:outline-background')).toBe(true);
    });

    it('draws a real line round a toast in forced colours, where its ink fill is repainted as the page and its shadow dropped', () => {
        renderFlash({ kind: 'success', message: 'Saved.' });

        expect(host.querySelector('.admin-toast')?.classList.contains(FORCED_EDGE)).toBe(true);
    });

    it('lets a long unbroken value break inside the message, so the dismiss button stays in the toast', () => {
        renderFlash({ kind: 'error', message: 'averyveryverylongunbrokenaddress@example.com' });

        const message = host.querySelector('.admin-toast p');

        expect(['min-w-0', 'flex-1', 'wrap-anywhere'].filter((name) => !message?.classList.contains(name))).toEqual([]);
    });

    it('marks an error toast by the alert glyph as well as its role, never by colour alone', () => {
        renderFlash({ kind: 'error', message: 'Nope.' });
        expect(host.querySelector('.admin-toast svg.lucide-circle-alert')?.getAttribute('aria-hidden')).toBe('true');

        act(() => host.querySelector<HTMLButtonElement>('.admin-toast-dismiss')?.click());
        renderFlash({ kind: 'success', message: 'Saved.' });
        expect(host.querySelector('.admin-toast svg.lucide-circle-alert')).toBeNull();
    });

    it('keeps the toaster where it stands: bottom right at a fixed width, across the top below the breakpoint, over every dialog', () => {
        renderFlash({ kind: 'success', message: 'Saved.' });

        const toaster = host.querySelector('.admin-toaster');

        for (const name of ['fixed', 'right-4', 'bottom-4', 'w-80', 'z-[60]', 'flex-col', 'gap-2', 'max-desktop:top-4', 'max-desktop:bottom-auto', 'max-desktop:left-4', 'max-desktop:w-auto']) {
            expect(toaster?.classList.contains(name)).toBe(true);
        }
    });

    it('shows a kind it does not recognise as given and clears it like any non-error', () => {
        renderFlash({ kind: 'celebration', message: 'Welcome back' });

        expect(host.querySelector('.admin-toast')?.getAttribute('data-kind')).toBe('celebration');
        act(() => vi.advanceTimersByTime(AUTO_CLEAR_MS));
        expect(messages()).toEqual([]);
    });

    it('renders nothing for a visit with no flash, and does not repeat a flash on a re-render', () => {
        const flash = { kind: 'error', message: 'Once.' };

        renderFlash(null);
        expect(messages()).toEqual([]);

        renderFlash(flash);
        renderFlash(flash);
        expect(messages()).toEqual(['Once.']);
    });

    it('pushes from anywhere under the provider', () => {
        function Pusher() {
            const { push } = useToasts();

            return (
                <button type="button" onClick={() => push('error', 'Nope.')}>
                    push
                </button>
            );
        }

        act(() =>
            root.render(
                <ToastProvider>
                    <Pusher />
                </ToastProvider>,
            ),
        );
        act(() => host.querySelector('button')?.click());

        expect(messages()).toEqual(['Nope.']);
    });
});
