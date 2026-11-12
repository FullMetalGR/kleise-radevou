import { act, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { keyedShell, WithShell } from '@/testing/keyed';
import { FeatureGone, NarrowedBanner } from './NarrowedBanner';

/*
 * The narrowing record as the shell shows it: one whole sentence per feature a resolve took away,
 * naming the feature, saying what members and bookings keep, and where to fix it; a link to
 * Billing for a user holding manageBilling and the word alone for everybody else. Nothing
 * dismisses it, because the record describes the subscription and not the reader. The sentence is
 * the one lib/features holds for the slug, one whole sentence of the shell's with the Billing
 * screen's name as its :billing placeholder, read here through the keyed table, so the name the
 * banner sets in the sentence shows where the placeholder stood. A free tenant's plan is the
 * console's, so for it the sentence names platform support and links nothing, whatever the reader
 * may do; a paying tenant's is unchanged.
 */

const shell = keyedShell();

const BILLING = shell.text('banners.billing');

const STAFF_GONE = shell.text('banners.features.staff.gone', { billing: BILLING });

/**
 * The sentence for a free tenant, whose plan is the console's and which has no Billing to add it
 * on.
 */
const STAFF_GONE_FREE = shell.text('banners.features.staff.gone_free');

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

function shown(node: ReactNode) {
    act(() => root.render(<WithShell>{node}</WithShell>));
}

function render(narrowed: string[], manageBilling: boolean, free = false) {
    shown(<NarrowedBanner narrowed={narrowed} manageBilling={manageBilling} free={free} />);
}

describe('NarrowedBanner', () => {
    it('renders one sentence per feature named, linking to Billing for a manageBilling holder', () => {
        render(['staff'], true);

        const banner = host.querySelector('.admin-narrowed');
        expect(banner?.getAttribute('role')).toBe('status');
        expect(banner?.classList.contains('admin-notice--warning')).toBe(true);
        const lines = host.querySelectorAll('.admin-narrowed__line');
        expect(lines).toHaveLength(1);
        expect(lines[0]?.textContent).toBe(STAFF_GONE);
        expect(STAFF_GONE).toBe('[banners.features.staff.gone [banners.billing]]');
        expect(lines[0]?.getAttribute('data-feature')).toBe('staff');
        const link = host.querySelector<HTMLAnchorElement>('.admin-narrowed__billing');
        expect(link?.tagName).toBe('A');
        expect(link?.getAttribute('href')).toBe('/admin/billing');
        expect(link?.textContent).toBe(BILLING);
        expect(host.querySelector('.admin-notice__dismiss')).toBeNull();
    });

    it('names Billing in plain text for a user without manageBilling', () => {
        render(['staff'], false);

        expect(host.querySelector('.admin-narrowed__line')?.textContent).toBe(STAFF_GONE);
        expect(host.querySelector('a')).toBeNull();
        expect(host.querySelector('.admin-narrowed__billing')?.tagName).toBe('SPAN');
    });

    it('names platform support and links nothing on a free tenant, a manageBilling holder included', () => {
        render(['staff'], true, true);

        expect(host.querySelector('.admin-narrowed__line')?.textContent).toBe(STAFF_GONE_FREE);
        expect(host.querySelector('a')).toBeNull();
        expect(host.querySelector('.admin-narrowed__billing')).toBeNull();
    });

    it('renders nothing for an empty record', () => {
        render([], true);

        expect(host.innerHTML).toBe('');
    });

    it('renders nothing for a record naming only slugs this build does not know', () => {
        render(['nonsense'], true);

        expect(host.innerHTML).toBe('');
    });
});

describe('FeatureGone', () => {
    it('renders the one sentence of a known slug under the block it is given, the Billing link included', () => {
        shown(<FeatureGone slug="staff" manageBilling free={false} block="admin-elsewhere" />);

        const line = host.querySelector('p.admin-elsewhere__line');
        expect(line?.textContent).toBe(STAFF_GONE);
        expect(line?.getAttribute('data-feature')).toBe('staff');
        expect(host.querySelector('a.admin-elsewhere__billing')?.getAttribute('href')).toBe('/admin/billing');
    });

    it.each([true, false])('names platform support with no link and no Billing for a free tenant, manageBilling %s', (manageBilling) => {
        shown(<FeatureGone slug="staff" manageBilling={manageBilling} free block="admin-elsewhere" />);

        const line = host.querySelector('p.admin-elsewhere__line');
        expect(line?.textContent).toBe(STAFF_GONE_FREE);
        expect(line?.getAttribute('data-feature')).toBe('staff');
        expect(host.querySelector('a')).toBeNull();
        expect(host.querySelector('.admin-elsewhere__billing')).toBeNull();
    });

    it('renders nothing for a slug this build does not know', () => {
        shown(<FeatureGone slug="nonsense" manageBilling free={false} block="admin-elsewhere" />);

        expect(host.innerHTML).toBe('');
    });
});
