import { describe, expect, it, vi } from 'vitest';
import { said } from '../bookingWords';
import type { Api } from './api';
import { CATALOG_FRESH_MS, CATALOG_KEY, type CatalogService, catalogQuery, categoryGroups, fetchCatalog, OTHER_HEADING, parseCatalog, serviceById, SERVICES_PATH, staffOf } from './catalog';
import type { Fetch } from './config';

/*
 * The catalog query over its wire shape: the key, the freshness, the path, and a parser
 * that refuses anything short of the published shape.
 */

export const HAIRCUT = { id: 1, name: 'Haircut', description: 'Wash, cut and finish.', duration_min: 45, price_minor: 3500, currency: 'EUR', category: null, online: true, staff: [{ id: 2, name: 'Elena' }, { id: 3, name: 'Nikos' }] };
export const BEARD = { id: 4, name: 'Beard trim', description: '', duration_min: 20, price_minor: 1500, currency: 'EUR', category: null, online: true, staff: [{ id: 3, name: 'Nikos' }] };
export const YEN = { id: 5, name: 'Head spa', description: '', duration_min: 30, price_minor: 5000, currency: 'JPY', category: null, online: true, staff: [] };
export const WIRE = { services: [HAIRCUT, BEARD, YEN], max_chain_length: 5 };

const PARSED = {
    services: [
        { id: 1, name: 'Haircut', description: 'Wash, cut and finish.', durationMin: 45, priceMinor: 3500, currency: 'EUR', category: null, staff: [{ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null }, { id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] },
        { id: 4, name: 'Beard trim', description: '', durationMin: 20, priceMinor: 1500, currency: 'EUR', category: null, staff: [{ id: 3, name: 'Nikos', photoUrl: null, title: null, bio: null }] },
        { id: 5, name: 'Head spa', description: '', durationMin: 30, priceMinor: 5000, currency: 'JPY', category: null, staff: [] },
    ],
    maxChainLength: 5,
};

function answering(body: unknown, status = 200): Fetch {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function api(fetchFn: Fetch): Api {
    return { origin: 'https://salon-x.kleiseradevou.gr', fetch: fetchFn };
}

describe('parseCatalog', () => {
    it('reads the services and the cap off the wire shape', () => {
        expect(parseCatalog(WIRE)).toEqual(PARSED);
    });

    it('accepts an empty catalog', () => {
        expect(parseCatalog({ services: [], max_chain_length: 5 })).toEqual({ services: [], maxChainLength: 5 });
    });

    it('accepts a free service at price zero', () => {
        expect(parseCatalog({ services: [{ ...YEN, price_minor: 0 }], max_chain_length: 1 })?.services[0]?.priceMinor).toBe(0);
    });

    it.each([
        ['null', null],
        ['a list', [HAIRCUT]],
        ['no services key', { max_chain_length: 5 }],
        ['services not a list', { services: {}, max_chain_length: 5 }],
        ['no cap', { services: [] }],
        ['a zero cap', { services: [], max_chain_length: 0 }],
        ['a string cap', { services: [], max_chain_length: '5' }],
        ['a service that is not an object', { services: [7], max_chain_length: 5 }],
        ['a service without an id', { services: [{ ...HAIRCUT, id: undefined }], max_chain_length: 5 }],
        ['a service with a string id', { services: [{ ...HAIRCUT, id: '1' }], max_chain_length: 5 }],
        ['a service without a name', { services: [{ ...HAIRCUT, name: undefined }], max_chain_length: 5 }],
        ['a service with a null description', { services: [{ ...HAIRCUT, description: null }], max_chain_length: 5 }],
        ['a zero duration', { services: [{ ...HAIRCUT, duration_min: 0 }], max_chain_length: 5 }],
        ['a decimal price', { services: [{ ...HAIRCUT, price_minor: 35.5 }], max_chain_length: 5 }],
        ['a formatted price', { services: [{ ...HAIRCUT, price_minor: '35.00' }], max_chain_length: 5 }],
        ['a missing currency', { services: [{ ...HAIRCUT, currency: undefined }], max_chain_length: 5 }],
        ['staff not a list', { services: [{ ...HAIRCUT, staff: null }], max_chain_length: 5 }],
        ['a staff member without a name', { services: [{ ...HAIRCUT, staff: [{ id: 2 }] }], max_chain_length: 5 }],
        ['a staff member with a zero id', { services: [{ ...HAIRCUT, staff: [{ id: 0, name: 'x' }] }], max_chain_length: 5 }],
        ['a numeric category', { services: [{ ...HAIRCUT, category: 3 }], max_chain_length: 5 }],
        ['a staff member with a numeric title', { services: [{ ...HAIRCUT, staff: [{ id: 2, name: 'Elena', title: 3 }] }], max_chain_length: 5 }],
        ['a staff member with an object for a photo', { services: [{ ...HAIRCUT, staff: [{ id: 2, name: 'Elena', photo_url: {} }] }], max_chain_length: 5 }],
        ['a staff member with a list for a bio', { services: [{ ...HAIRCUT, staff: [{ id: 2, name: 'Elena', bio: ['Ten years.'] }] }], max_chain_length: 5 }],
    ])('refuses %s', (_label, value) => {
        expect(parseCatalog(value)).toBeNull();
    });

    it('carries no key it was not told about', () => {
        const parsed = parseCatalog({ services: [{ ...HAIRCUT, payment_mode: 'online', requires_approval: true }], max_chain_length: 5, entitlements: ['staff'] });

        expect(JSON.stringify(parsed)).not.toContain('online');
        expect(JSON.stringify(parsed)).not.toContain('entitlements');
        expect(Object.keys(parsed?.services[0] ?? {}).sort()).toEqual(['category', 'currency', 'description', 'durationMin', 'id', 'name', 'priceMinor', 'staff']);
    });

    it('reads a staff member\'s photo URL, title and bio as text, an empty or an absent one as null, and nothing else of theirs', () => {
        const person = (fields: Record<string, unknown>) => parseCatalog({ services: [{ ...HAIRCUT, staff: [{ id: 2, name: 'Elena', ...fields }] }], max_chain_length: 5 })?.services[0]?.staff[0];
        const photo = 'https://salon-x.kleiseradevou.gr/images/staff-0123456789abcdef.png';

        expect(person({ photo_url: photo, title: 'Senior stylist', bio: 'Ten years at the chair.', email: 'elena@salon-x.example' })).toEqual({ id: 2, name: 'Elena', photoUrl: photo, title: 'Senior stylist', bio: 'Ten years at the chair.' });
        expect(person({ photo_url: null, title: null, bio: null })).toEqual({ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null });
        expect(person({})).toEqual({ id: 2, name: 'Elena', photoUrl: null, title: null, bio: null });
        expect(person({ title: '', bio: '' })).toMatchObject({ title: null, bio: null });
    });

    it('reads the category as text, an empty or an absent one as null', () => {
        const categoryOf = (category: unknown) => parseCatalog({ services: [{ ...HAIRCUT, category }], max_chain_length: 5 })?.services[0]?.category;
        const bare: Record<string, unknown> = { ...HAIRCUT };
        delete bare['category'];

        expect(categoryOf('Hair')).toBe('Hair');
        expect(categoryOf('')).toBeNull();
        expect(categoryOf(null)).toBeNull();
        expect(parseCatalog({ services: [bare], max_chain_length: 5 })?.services[0]?.category).toBeNull();
    });
});

describe('categoryGroups', () => {
    // Other as the page's language reads it, here the keyed table's.
    const OTHER = said(OTHER_HEADING);
    const row = (id: number, category: string | null): CatalogService => ({ id, name: `Service ${id}`, description: '', durationMin: 30, priceMinor: 1000, currency: 'EUR', category, staff: [] });
    const headings = (services: CatalogService[]) => categoryGroups(services, OTHER)?.map((group) => [group.heading, group.services.map((service) => service.id)]) ?? null;

    it('groups by exact name in the order the catalogue first names each, rows in catalogue order, Other last', () => {
        expect(headings([row(1, 'Nails'), row(2, null), row(3, 'Hair'), row(4, 'Nails'), row(5, 'hair')])).toEqual([
            ['Nails', [1, 4]],
            ['Hair', [3]],
            ['hair', [5]],
            [OTHER, [2]],
        ]);
    });

    it('folds a category spelled as Other reads in the page\'s language into the Other heading, so no heading is printed twice', () => {
        expect(headings([row(1, OTHER), row(2, 'Hair'), row(3, null)])).toEqual([
            ['Hair', [2]],
            [OTHER, [1, 3]],
        ]);
    });

    it('shows no headings when every row falls under one category, under none, or the catalogue is empty', () => {
        expect(categoryGroups([row(1, 'Hair'), row(2, 'Hair')], OTHER)).toBeNull();
        expect(categoryGroups([row(1, null), row(2, null)], OTHER)).toBeNull();
        expect(categoryGroups([], OTHER)).toBeNull();
        expect(headings([row(1, 'Hair'), row(2, null)])).toEqual([['Hair', [1]], [OTHER, [2]]]);
    });
});

describe('fetchCatalog', () => {
    it('GETs /api/v1/services on the API origin', async () => {
        const fetchFn = vi.fn(answering(WIRE));

        await expect(fetchCatalog(api(fetchFn))).resolves.toEqual(PARSED);

        expect(fetchFn).toHaveBeenCalledWith(`https://salon-x.kleiseradevou.gr${SERVICES_PATH}`, { headers: { Accept: 'application/json' }, credentials: 'omit' });
    });

    it('rejects an answer that is not the catalog', async () => {
        await expect(fetchCatalog(api(answering({ tenant_name: 'x' })))).rejects.toThrow('catalog');
    });
});

describe('catalogQuery', () => {
    it('is keyed services and fresh for five minutes', () => {
        const options = catalogQuery(api(answering(WIRE)));

        expect(options.queryKey).toEqual(['services']);
        expect(CATALOG_KEY).toEqual(['services']);
        expect(options.staleTime).toBe(5 * 60 * 1000);
        expect(CATALOG_FRESH_MS).toBe(300_000);
    });
});

describe('the lookups', () => {
    it('find a service by id and answer undefined for one the catalog no longer lists', () => {
        expect(serviceById(PARSED, 4)?.name).toBe('Beard trim');
        expect(serviceById(PARSED, 99)).toBeUndefined();
        expect(serviceById(undefined, 1)).toBeUndefined();
    });

    it('answer the staff a service lists, and nobody for an unknown service or no catalog', () => {
        expect(staffOf(PARSED, 1).map((person) => person.name)).toEqual(['Elena', 'Nikos']);
        expect(staffOf(PARSED, 5)).toEqual([]);
        expect(staffOf(PARSED, 99)).toEqual([]);
        expect(staffOf(undefined, 1)).toEqual([]);
    });
});
