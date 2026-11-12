import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { formatCount } from '@/lib/format';
import { ShellProvider } from '@/lib/strings';
import { KEYED_SHELL, keyedShell } from '@/testing/keyed';
import { mount, type Mounted } from './harness';
import { StatusChip, statusLabel } from './status-chip';

/*
 * The status chips: nine statuses separated by shape first, colour agreeing. Live for confirmed,
 * attention for awaiting approval, provisional for the two other held states, void for cancelled,
 * rejected and no-show, past for completed and expired. A status the client does not know renders
 * the base chip with the raw value as its label. A catalog row's active and inactive keep their two
 * shapes and take the word their caller gives. The compact form, the month overview's and Home's,
 * reads a booking status's whole kit.counted line chosen by the count, the figure in its own span.
 * Each word is the shell's, read through the keyed table.
 */

const shell = keyedShell();

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const SHAPES = ['live', 'attention', 'provisional', 'void', 'past'];

function chip(status: string): { shape: string | null; label: string | null; base: boolean } {
    view.render(<StatusChip status={status} />);

    const element = view.host.querySelector('.admin-chip');
    const shape = SHAPES.find((name) => element?.classList.contains(`admin-chip--${name}`)) ?? null;

    return { shape, label: element?.textContent ?? null, base: element !== null };
}

describe('the status chip', () => {
    it('maps each of the nine statuses to the shape its family names', () => {
        const shapes = Object.fromEntries(
            ['confirmed', 'awaiting_approval', 'pending', 'awaiting_payment', 'cancelled', 'rejected', 'no_show', 'completed', 'expired'].map((status) => [status, chip(status).shape]),
        );

        expect(shapes).toEqual({
            confirmed: 'live',
            awaiting_approval: 'attention',
            pending: 'provisional',
            awaiting_payment: 'provisional',
            cancelled: 'void',
            rejected: 'void',
            no_show: 'void',
            completed: 'past',
            expired: 'past',
        });
    });

    it('labels a known booking status in words, the kit\'s, and statusLabel says the same', () => {
        expect(chip('awaiting_approval').label).toBe(shell.text('kit.statuses.awaiting_approval'));
        expect(chip('no_show').label).toBe(shell.text('kit.statuses.no_show'));
        expect(statusLabel('no_show', shell)).toBe(shell.text('kit.statuses.no_show'));
        expect(statusLabel('on_hold_forever', shell)).toBe('on_hold_forever');
    });

    it('draws a catalog row\'s two states in their shapes with the label its caller gives', () => {
        view.render(<StatusChip status="active" label="label-token" />);
        const active = view.host.querySelector('.admin-chip');
        expect(active?.classList.contains('admin-chip--live')).toBe(true);
        expect(active?.textContent).toBe('label-token');

        view.render(<StatusChip status="inactive" label="other-token" />);
        const inactive = view.host.querySelector('.admin-chip');
        expect(inactive?.classList.contains('admin-chip--past')).toBe(true);
        expect(inactive?.textContent).toBe('other-token');
    });

    it('draws a catalog state given no label as its raw value, the shell holding no word for it', () => {
        expect(chip('active')).toEqual({ shape: 'live', label: 'active', base: true });
        expect(chip('inactive')).toEqual({ shape: 'past', label: 'inactive', base: true });
        expect(statusLabel('inactive', shell)).toBe('inactive');
    });

    it('renders an unknown status as the base chip with the raw value as its label', () => {
        expect(chip('on_hold_forever')).toEqual({ shape: null, label: 'on_hold_forever', base: true });
    });

    it('reads a counted booking status as its whole kit.counted line, the figure in its own span', () => {
        for (const count of [1, 2, 1234]) {
            view.render(<StatusChip status="awaiting_approval" count={count} />);

            const element = view.host.querySelector('.admin-chip');
            expect(element?.classList.contains('admin-chip--attention')).toBe(true);
            expect(element?.classList.contains('admin-chip--compact')).toBe(true);
            expect(element?.getAttribute('data-status')).toBe('awaiting_approval');
            expect(element?.querySelector('.admin-chip__count')?.textContent).toBe(formatCount(count, shell.locale));
            expect(element?.textContent).toBe(shell.choice('kit.counted.awaiting_approval', count));
            expect(element?.textContent).not.toContain(shell.text('kit.statuses.awaiting_approval'));
        }

        view.render(<StatusChip status="no_show" count={2} />);
        expect(view.host.querySelector('.admin-chip')?.textContent).toBe(shell.choice('kit.counted.no_show', 2));

        view.render(<StatusChip status="confirmed" />);
        expect(view.host.querySelector('.admin-chip')?.classList.contains('admin-chip--compact')).toBe(false);
        expect(view.host.querySelector('.admin-chip__count')).toBeNull();
    });

    it('sets each run of a counted line\'s words in its own label span, the figure outside them', () => {
        const marker = '\u0001figure\u0001';
        const statuses = ['confirmed', 'awaiting_approval', 'pending', 'awaiting_payment', 'cancelled', 'rejected', 'no_show', 'completed', 'expired'] as const;

        for (const language of ['en', 'el'] as const) {
            const words = keyedShell(language);

            for (const status of statuses) {
                for (const count of [1, 2]) {
                    view.render(
                        <ShellProvider strings={KEYED_SHELL} language={language}>
                            <StatusChip status={status} count={count} />
                        </ShellProvider>,
                    );

                    const runs = words
                        .choice(`kit.counted.${status}`, count, { count: marker })
                        .split(marker)
                        .map((part) => part.trim())
                        .filter((part) => part !== '');
                    const element = view.host.querySelector('.admin-chip');
                    const labels = [...(element?.querySelectorAll('.admin-chip__label') ?? [])].map((span) => span.textContent);
                    const figure = element?.querySelector('.admin-chip__count');

                    expect(labels).toEqual(runs);
                    expect(figure?.textContent).toBe(formatCount(count, words.locale));
                    expect(figure?.closest('.admin-chip__label')).toBeNull();
                    expect(figure?.querySelector('.admin-chip__label')).toBeNull();
                }
            }
        }
    });

    it('spells the counted line\'s figure in the shell\'s locale, grouped as the language groups it', () => {
        view.render(
            <ShellProvider strings={KEYED_SHELL} language="el">
                <StatusChip status="awaiting_approval" count={1234} />
            </ShellProvider>,
        );
        const greek = keyedShell('el');
        expect(view.host.querySelector('.admin-chip__count')?.textContent).toBe(formatCount(1234, greek.locale));
        expect(view.host.querySelector('.admin-chip')?.textContent).toBe(greek.choice('kit.counted.awaiting_approval', 1234));
        expect(formatCount(1234, greek.locale)).not.toBe(formatCount(1234, shell.locale));
    });

    it('splices the figure before the raw value for a status the chip does not know', () => {
        view.render(<StatusChip status="on_hold_forever" count={1234} />);
        const element = view.host.querySelector('.admin-chip');
        expect(element?.querySelector('.admin-chip__count')?.textContent).toBe(formatCount(1234, shell.locale));
        expect(element?.querySelector('.admin-chip__label')?.textContent).toBe('on_hold_forever');
        expect(element?.textContent).toBe(`${formatCount(1234, shell.locale)} on_hold_forever`);
    });
});
