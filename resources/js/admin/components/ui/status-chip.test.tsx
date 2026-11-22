import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { keyedShell } from '@/testing/keyed';
import { mount, type Mounted } from './harness';
import { StatusChip, statusLabel } from './status-chip';

/*
 * The status chips: nine statuses in five families. Live for confirmed, attention for awaiting
 * approval, provisional for the two other held states, void for cancelled, rejected and no-show,
 * past for completed and expired. Each status then has a look of its own, keyed by its data-status
 * in the stylesheet, and an icon, so no state rests on its colour: a tick, a clock, a card, an
 * hourglass, a cross, an alert or a timer, and the four that ended without the visit strike their
 * label through, never the icon. A status the client does not know renders the base chip with the
 * raw value as its label. A catalog row's active and inactive keep their two shapes and take the
 * word their caller gives. The compact form Home's activity line passes is the same chip, tighter,
 * at the admin's least type. A chip carries no figure: a count beside a status is its screen's.
 * Each word is the shell's, read through the keyed table.
 */

const shell = keyedShell();

/** The admin stylesheet's innermost rules, comments dropped: each one's selectors and the words it applies. */
const RULES = [...readFileSync(resolve(process.cwd(), 'resources/js/admin/app.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((match) => ({
    selectors: (match[1] ?? '').split(',').map((selector) => selector.trim()),
    applies: (match[2] ?? '')
        .replace(/@apply/g, '')
        .split(/[\s;]+/)
        .filter((word) => word !== ''),
}));

/** What the stylesheet applies to a selector, from every rule that names it, sorted. */
function applied(selector: string): string[] {
    return RULES.filter((rule) => rule.selectors.includes(selector))
        .flatMap((rule) => rule.applies)
        .sort();
}

const ruled = (status: string) => `.admin-chip[data-status="${status}"]`;

/** The looks a status chip wears, each its edge, its ground and its ink. */
const POSITIVE = ['border-transparent', 'bg-positive-soft', 'text-positive-ink'];
const WAITING = ['border-attention-line', 'bg-attention-soft-strong', 'text-attention-ink'];
const HELD = ['border-transparent', 'bg-transparent', 'text-primary-deep', 'outline-[1.5px]', '-outline-offset-[1.5px]', 'outline-dashed', 'outline-primary'];
const SOFT = ['border-transparent', 'bg-muted', 'text-muted-foreground'];
const ENDED = ['border-ink-tertiary', 'bg-transparent', 'text-ink-tertiary'];
const REFUSED = ['border-transparent', 'bg-destructive-soft', 'text-destructive'];

/** Each status the chip knows: the icon it draws, or none, the look its own rule gives it, and whether its label is struck. */
const LOOKS: Record<string, { icon: string | null; look: string[]; struck: boolean }> = {
    awaiting_approval: { icon: 'lucide-clock', look: WAITING, struck: false },
    confirmed: { icon: 'lucide-check', look: POSITIVE, struck: false },
    active: { icon: 'lucide-check', look: POSITIVE, struck: false },
    awaiting_payment: { icon: 'lucide-credit-card', look: HELD, struck: false },
    pending: { icon: 'lucide-hourglass', look: HELD, struck: false },
    completed: { icon: 'lucide-check', look: SOFT, struck: false },
    inactive: { icon: null, look: SOFT, struck: false },
    cancelled: { icon: 'lucide-x', look: ENDED, struck: true },
    rejected: { icon: 'lucide-x', look: REFUSED, struck: true },
    no_show: { icon: 'lucide-circle-alert', look: ENDED, struck: true },
    expired: { icon: 'lucide-timer', look: ENDED, struck: true },
};

/** A class naming one of the stock palette's colours by its shade, which no admin part paints with. */
const RAW_PALETTE = /(^|[:-])(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d/;

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

    it('takes no count, a figure beside a status being its screen\'s to spell', () => {
        // @ts-expect-error the chip draws a status in its word and its icon, never a figure
        const counted = <StatusChip status="awaiting_approval" count={3} />;

        expect(counted.props.status).toBe('awaiting_approval');
    });
});

describe('each status\'s look', () => {
    it('gives each status its icon before its label and the look its own rule draws', () => {
        for (const [status, { icon, look }] of Object.entries(LOOKS)) {
            view.render(<StatusChip status={status} />);

            const element = view.host.querySelector('.admin-chip');
            const svgs = [...(element?.querySelectorAll('.admin-chip__icon svg') ?? [])];
            expect(element?.getAttribute('data-status')).toBe(status);
            expect([status, svgs.length, icon === null || svgs[0]?.classList.contains(icon)]).toEqual([status, icon === null ? 0 : 1, true]);
            expect(svgs.map((svg) => svg.getAttribute('aria-hidden'))).toEqual(svgs.map(() => 'true'));
            expect(element?.firstElementChild?.classList.contains(icon === null ? 'admin-chip__label' : 'admin-chip__icon')).toBe(true);
            expect(element?.querySelector('.admin-chip__label')?.textContent).toBe(statusLabel(status, shell));
            expect([status, applied(ruled(status))]).toEqual([status, [...look].sort()]);
        }
    });

    it('strikes the label through for the four that ended without the visit, and no other', () => {
        const struck = Object.entries(LOOKS)
            .filter(([status]) => applied(`${ruled(status)} .admin-chip__label`).length > 0)
            .map(([status]) => status);

        expect(struck).toEqual(Object.entries(LOOKS).filter(([, { struck }]) => struck).map(([status]) => status));

        for (const status of struck) {
            expect(applied(`${ruled(status)} .admin-chip__label`)).toEqual(['decoration-1', 'line-through']);
        }
    });

    it('the strike is on the label alone, not the icon', () => {
        for (const status of ['cancelled', 'rejected', 'no_show', 'expired']) {
            view.render(<StatusChip status={status} />);

            const element = view.host.querySelector('.admin-chip');

            expect(element?.querySelector('.admin-chip__label .admin-chip__icon')).toBeNull();
            expect(element?.querySelector('.admin-chip__icon')?.closest('.admin-chip__label')).toBeNull();
            expect(applied(ruled(status))).not.toContain('line-through');
            expect(RULES.filter((rule) => rule.selectors.some((selector) => selector.includes('admin-chip__icon')) && rule.applies.includes('line-through'))).toEqual([]);
        }
    });

    it('an unknown status keeps the base chip and its raw label', () => {
        view.render(<StatusChip status="on_hold_forever" />);

        const element = view.host.querySelector('.admin-chip');

        expect(element?.className).toBe('admin-chip');
        expect(element?.querySelector('.admin-chip__icon')).toBeNull();
        expect(element?.querySelector('.admin-chip__label')?.textContent).toBe('on_hold_forever');
        expect(applied(ruled('on_hold_forever'))).toEqual([]);
    });

    it('the compact chip, as Home\'s activity line passes it, keeps its icon and its word and type no smaller than 12px', () => {
        view.render(<StatusChip status="awaiting_approval" className="admin-chip--compact" />);

        const element = view.host.querySelector('.admin-chip');

        expect(element?.classList.contains('admin-chip--compact')).toBe(true);
        expect(element?.querySelector('.admin-chip__icon svg')?.classList.contains('lucide-clock')).toBe(true);
        expect([...(element?.children ?? [])].map((child) => child.className)).toEqual(['admin-chip__icon', 'admin-chip__label']);
        expect(element?.textContent).toBe(shell.text('kit.statuses.awaiting_approval'));
        expect(applied('.admin-chip--compact')).toEqual(['gap-1', 'leading-4.5', 'max-w-full', 'px-2', 'py-0', 'text-xs']);
        expect(applied('.admin-chip--compact:has(> .admin-chip__icon)')).toEqual(['pl-1.5']);
    });

    it('draws the chip a pill of 13px bold type, 3px by 11px round the label and 8px before an icon, the 1px edge counted in', () => {
        expect(applied('.admin-chip')).toEqual(
            ['inline-flex', 'items-center', 'gap-1.5', 'rounded-full', 'border', 'border-border', 'px-2.5', 'py-0.5', 'text-small', 'leading-[1.35]', 'font-bold', 'whitespace-nowrap', 'text-foreground'].sort(),
        );
        expect(applied('.admin-chip:has(> .admin-chip__icon)')).toEqual(['pl-1.75']);
        expect(applied('.admin-chip__icon')).toEqual(['inline-flex', 'shrink-0']);
    });

    it('keeps a look for each family, for the chips a screen spells by hand: past soft, provisional the dashed ring, void the struck grey ring', () => {
        expect(applied('.admin-chip--live')).toEqual([...POSITIVE].sort());
        expect(applied('.admin-chip--attention')).toEqual([...WAITING].sort());
        expect(applied('.admin-chip--provisional')).toEqual([...HELD].sort());
        expect(applied('.admin-chip--past')).toEqual([...SOFT].sort());
        expect(applied('.admin-chip--void')).toEqual([...ENDED].sort());
        expect(applied('.admin-chip--void:not([data-status])')).toEqual(['decoration-1', 'line-through']);
    });

    it('paints every chip with the design\'s colours, no class of a raw palette colour left', () => {
        const chips = RULES.filter((rule) => rule.selectors.some((selector) => selector.startsWith('.admin-chip')));

        expect(chips.length).toBeGreaterThan(10);
        expect(chips.flatMap((rule) => rule.applies).filter((word) => RAW_PALETTE.test(word))).toEqual([]);
    });
});
