import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Flash } from '../types';
import { KEYED_SHELL, keyedShell, WithShell } from '@/testing/keyed';
import { AUTO_CLEAR_MS, DISMISS, FlashToast, ToastProvider, useToasts } from './toasts';

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
