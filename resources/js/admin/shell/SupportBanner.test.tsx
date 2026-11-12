import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { TenantClock } from '@/lib/format';
import { keyedShell, WithShell } from '@/testing/keyed';
import { SUPPORT_LINE, supportLine, SupportBanner } from './SupportBanner';

/*
 * The support session's banner: for support acting in a session, one sentence naming the operator
 * as the shell names support everywhere, "Support" and the operator in brackets, and the
 * session's end converted to the tenant's clock in the tenant's formats, the zone named, with End
 * session beside it; End session shows its busy state while its post is in flight and refuses a
 * second press. Nothing at all for a user, whose support prop is null. The sentence is the
 * shell's, one whole line with :operator, :end and :zone, read here through the keyed table,
 * which shows what was put into it.
 */

const shell = keyedShell();

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const SESSION = { operator: 'Pat Operator', ends_at: '2026-11-02T07:00:00+00:00' };

const LINE = shell.text(SUPPORT_LINE, { operator: 'Pat Operator', end: '02/11/2026 09:00', zone: 'Europe/Athens' });

let host: HTMLDivElement;
let root: Root;

beforeEach(() => {
    host = document.createElement('div');
    document.body.append(host);
    root = createRoot(host);
});

afterEach(() => {
    act(() => root.unmount());
    host.remove();
});

function render(support: typeof SESSION | null, ending: boolean, onEnd: () => void = () => {}) {
    act(() =>
        root.render(
            <WithShell>
                <SupportBanner support={support} clock={CLOCK} ending={ending} onEnd={onEnd} />
            </WithShell>,
        ),
    );
}

function end(): HTMLButtonElement | null {
    return host.querySelector<HTMLButtonElement>('.admin-support-banner__end');
}

describe('SupportBanner', () => {
    it('names the operator and the end on the tenant\'s clock, the zone named, with End session', () => {
        render(SESSION, false);

        const banner = host.querySelector('.admin-support-banner');
        expect(banner?.getAttribute('role')).toBe('status');
        expect(host.querySelector('.admin-support-banner__line')?.textContent).toBe(LINE);
        expect(LINE).toBe('[banners.support.line Pat Operator 02/11/2026 09:00 Europe/Athens]');
        expect(end()?.textContent).toBe(shell.text('banners.support.end'));
        expect(end()?.disabled).toBe(false);
        expect(host.querySelector('.admin-notice__dismiss')).toBeNull();
    });

    it('spells the end in the tenant\'s own formats, a twelve-hour clock and another zone among them', () => {
        expect(supportLine(SESSION, { timezone: 'America/New_York', dateFormat: 'Y-m-d', timeFormat: 'g:i A' }, shell)).toBe(
            shell.text(SUPPORT_LINE, { operator: 'Pat Operator', end: '2026-11-02 2:00 AM', zone: 'America/New_York' }),
        );
    });

    it('asks for the end once on End session', () => {
        const onEnd = vi.fn();
        render(SESSION, false, onEnd);

        act(() => end()?.click());

        expect(onEnd).toHaveBeenCalledOnce();
    });

    it('shows the busy state while the end is in flight and refuses a second press', () => {
        const onEnd = vi.fn();
        render(SESSION, true, onEnd);

        expect(end()?.textContent).toBe(shell.text('banners.support.ending'));
        expect(end()?.getAttribute('aria-busy')).toBe('true');
        expect(end()?.disabled).toBe(true);

        act(() => end()?.click());

        expect(onEnd).not.toHaveBeenCalled();
    });

    it('renders nothing for a user, whose support prop is null', () => {
        render(null, false);

        expect(host.innerHTML).toBe('');
    });
});
