import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Confirm } from './confirm';
import { drag, key, mount, press, settle, type Mounted } from './harness';
import { keyedShell } from '@/testing/keyed';

/*
 * The confirm, the one the booking dialog's Reject and Cancel, the console's Suspend and its
 * Resume all open: a modal holding its body, Keep it and the confirm, focus landing on the first
 * control, which is never the confirm. Keep it, Escape and a backdrop press dismiss it and fire
 * nothing; the confirm fires once. While its confirm is in flight no path dismisses it, and Keep
 * it says why in text beside it. The confirm is destructive unless the confirm says otherwise.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const one = (selector: string) => document.body.querySelector(selector);

function Harness({ busy = false, destructive, onConfirm, onDismiss }: { busy?: boolean; destructive?: boolean; onConfirm: () => void; onDismiss: () => void }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" className="opener" onClick={() => setOpen(true)}>
                Resume
            </button>
            <Confirm
                open={open}
                title="Resume Luna Hair Studio"
                name="test-prompt"
                confirmLabel="Resume"
                busyLabel="Resuming"
                pending="The resumption has been sent. This closes once the server answers."
                busy={busy}
                destructive={destructive}
                onConfirm={onConfirm}
                onDismiss={() => {
                    onDismiss();
                    setOpen(false);
                }}
            >
                <p className="test-prompt__warning">The booking page opens again.</p>
            </Confirm>
        </>
    );
}

function opened(props: { busy?: boolean; destructive?: boolean } = {}) {
    const onConfirm = vi.fn();
    const onDismiss = vi.fn();

    view.render(<Harness {...props} onConfirm={onConfirm} onDismiss={onDismiss} />);
    press(one('.opener'));

    return { onConfirm, onDismiss };
}

describe('the confirm', () => {
    it('titles a modal under its name, holding the body, Keep it and the confirm, focus landing on Keep it', async () => {
        opened();
        await settle();

        expect(one('.test-prompt[role="dialog"] .admin-modal__title')?.textContent).toBe('Resume Luna Hair Studio');
        expect(one('.test-prompt__warning')?.textContent).toBe('The booking page opens again.');
        expect([...document.querySelectorAll('.test-prompt .admin-modal__footer button')].map((button) => button.textContent)).toEqual([keyedShell().text('kit.confirm.keep'), 'Resume']);
        expect(document.activeElement).toBe(one('.test-prompt__keep'));
    });

    it.each([
        ['Keep it', () => press(one('.test-prompt__keep'))],
        ['Escape', () => key(one('.test-prompt'), 'Escape')],
        [
            'a backdrop press',
            () => {
                const backdrop = one('.admin-modal__backdrop');
                drag(backdrop, backdrop);
            },
        ],
    ])('is dismissed by %s, firing nothing', (_path, dismiss) => {
        const { onConfirm, onDismiss } = opened();

        dismiss();

        expect(one('.test-prompt')).toBeNull();
        expect(onDismiss).toHaveBeenCalledTimes(1);
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('fires once on the confirm', () => {
        const { onConfirm, onDismiss } = opened();

        press(one('.test-prompt__confirm'));

        expect(onConfirm).toHaveBeenCalledTimes(1);
        expect(onDismiss).not.toHaveBeenCalled();
    });

    it('refuses every dismissal while its confirm is in flight, Keep it saying why beside it, the confirm busy', () => {
        const { onConfirm, onDismiss } = opened({ busy: true });

        press(one('.test-prompt__keep'));
        key(one('.test-prompt'), 'Escape');
        const backdrop = one('.admin-modal__backdrop');
        drag(backdrop, backdrop);
        press(one('.test-prompt__confirm'));

        expect(one('.test-prompt')).not.toBeNull();
        expect(onDismiss).not.toHaveBeenCalled();
        expect(onConfirm).not.toHaveBeenCalled();

        const keep = one('.test-prompt__keep');
        expect(keep?.hasAttribute('disabled')).toBe(true);
        expect(document.getElementById(keep?.getAttribute('aria-describedby') ?? '')?.textContent).toBe('The resumption has been sent. This closes once the server answers.');
        expect(one('.test-prompt__confirm')?.textContent).toBe('Resuming');
        expect(one('.test-prompt__confirm')?.getAttribute('aria-busy')).toBe('true');
    });

    it('carries no pending note and no description on Keep it while nothing is in flight', () => {
        opened();

        expect(one('.test-prompt__pending')).toBeNull();
        expect(one('.test-prompt__keep')?.hasAttribute('aria-describedby')).toBe(false);
    });

    it('is destructive unless told otherwise', () => {
        opened();
        const destructive = one('.test-prompt__confirm')?.className;
        press(one('.test-prompt__keep'));
        view.unmount();
        view = mount();
        opened({ destructive: false });
        const plain = one('.test-prompt__confirm')?.className;

        expect(destructive).toContain('bg-destructive');
        expect(plain).not.toContain('bg-destructive');
        expect(plain).toContain('bg-primary');
    });
});
