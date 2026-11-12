import { queryOptions } from '@tanstack/react-query';
import { getJson, type Api } from './api';
import type { LineKey } from './words';

/*
 * The catalog: GET /services, keyed ['services'], fresh for five minutes so that every remount
 * and refocus does not refetch a near-static list. The numbers that bind are the server's
 * anyway, since a hold re-prices under lock, so a briefly stale label costs pixels. The payload
 * is checked key by key, as the boot config is: a service that does not have the keys of the
 * published shape is not a catalog. The category is text or null, an absent one read as null;
 * the online flag is not read, since the public catalogue lists no service that is not offered
 * online. A performer's photo URL, title and bio are read the category's way, so a catalogue
 * from before they were published still parses.
 */

export interface CatalogStaff {
    readonly id: number;
    readonly name: string;
    /** The photo served on the tenant's origin, null for none. */
    readonly photoUrl: string | null;
    readonly title: string | null;
    readonly bio: string | null;
}

export interface CatalogService {
    readonly id: number;
    readonly name: string;
    readonly description: string;
    readonly durationMin: number;
    readonly priceMinor: number;
    readonly currency: string;
    /** The heading the picker lists the row under, null for none. */
    readonly category: string | null;
    readonly staff: readonly CatalogStaff[];
}

/** The rows under one heading of the picker, in catalogue order. */
export interface ServiceGroup {
    readonly heading: string;
    readonly services: readonly CatalogService[];
}

/** The heading of the rows under no category, which the picker lists last. */
export const OTHER_HEADING: LineKey = 'service.other';

export interface Catalog {
    readonly services: readonly CatalogService[];
    /** The server's cap on segments in one chain, which the add button matches. */
    readonly maxChainLength: number;
}

export const SERVICES_PATH = '/api/v1/services';

export const CATALOG_KEY = ['services'] as const;

export const CATALOG_FRESH_MS = 5 * 60 * 1000;

export function catalogQuery(api: Api) {
    return queryOptions({
        queryKey: CATALOG_KEY,
        queryFn: () => fetchCatalog(api),
        staleTime: CATALOG_FRESH_MS,
    });
}

export async function fetchCatalog(api: Api): Promise<Catalog> {
    const catalog = parseCatalog(await getJson(api, SERVICES_PATH));

    if (catalog === null) {
        throw new Error(`GET ${SERVICES_PATH} answered something other than the catalog.`);
    }

    return catalog;
}

export function parseCatalog(value: unknown): Catalog | null {
    const record = asRecord(value);
    const services = record?.['services'];
    const maxChainLength = record?.['max_chain_length'];

    if (!Array.isArray(services) || !isPositiveInteger(maxChainLength)) {
        return null;
    }

    const parsed: CatalogService[] = [];

    for (const item of services) {
        const service = parseService(item);

        if (service === null) {
            return null;
        }

        parsed.push(service);
    }

    return { services: parsed, maxChainLength };
}

function parseService(value: unknown): CatalogService | null {
    const record = asRecord(value);

    if (record === null) {
        return null;
    }

    const { id, name, description, duration_min: durationMin, price_minor: priceMinor, currency, staff } = record;
    const category = optionalText(record['category']);

    if (!isPositiveInteger(id) || typeof name !== 'string' || typeof description !== 'string' || typeof currency !== 'string') {
        return null;
    }

    if (category === undefined) {
        return null;
    }

    if (!isPositiveInteger(durationMin) || !Number.isInteger(priceMinor) || !Array.isArray(staff)) {
        return null;
    }

    const people = staff.map(parseStaff);

    if (people.some((person) => person === null)) {
        return null;
    }

    return { id, name, description, durationMin, priceMinor: priceMinor as number, currency, category, staff: people as CatalogStaff[] };
}

/**
 * An optional text of the published shape, a category or a performer's photo URL, title or bio:
 * its text, or null for none, an absent or empty one read as none; undefined for anything else,
 * which is not the published shape.
 */
function optionalText(value: unknown): string | null | undefined {
    if (value === undefined || value === null || value === '') {
        return null;
    }

    return typeof value === 'string' ? value : undefined;
}

function parseStaff(value: unknown): CatalogStaff | null {
    const record = asRecord(value);
    const id = record?.['id'];
    const name = record?.['name'];
    const texts = ['photo_url', 'title', 'bio'].map((key) => optionalText(record?.[key]));

    if (!isPositiveInteger(id) || typeof name !== 'string' || texts.includes(undefined)) {
        return null;
    }

    const [photoUrl = null, title = null, bio = null] = texts;

    return { id, name, photoUrl, title, bio };
}

function asRecord(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : null;
}

function isPositiveInteger(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value > 0;
}

/**
 * The picker's headings: a group per category by its exact name in the order the catalogue first
 * names it, the rows in catalogue order, and Other last for the rows under none, a category
 * spelled as Other is in the page's language among them so no heading is printed twice. A
 * catalogue whose rows all fall in one group, every row under one category or under none, has no
 * headings to show and answers null. Other is the heading OTHER_HEADING reads in the language the
 * page is in, which the caller hands in.
 */
export function categoryGroups(services: readonly CatalogService[], otherHeading: string): readonly ServiceGroup[] | null {
    const named = new Map<string, CatalogService[]>();
    const other: CatalogService[] = [];

    for (const service of services) {
        const heading = service.category ?? otherHeading;

        if (heading === otherHeading) {
            other.push(service);
            continue;
        }

        named.set(heading, [...(named.get(heading) ?? []), service]);
    }

    const groups: ServiceGroup[] = [...named].map(([heading, rows]) => ({ heading, services: rows }));

    if (other.length > 0) {
        groups.push({ heading: otherHeading, services: other });
    }

    return groups.length > 1 ? groups : null;
}

/**
 * The service a segment names, or undefined when the catalog no longer lists it.
 */
export function serviceById(catalog: Catalog | undefined, id: number): CatalogService | undefined {
    return catalog?.services.find((service) => service.id === id);
}

/**
 * The staff a segment's service lists: empty when it lists nobody or the catalog has no such
 * service, which the staff step renders nothing for.
 */
export function staffOf(catalog: Catalog | undefined, serviceId: number): readonly CatalogStaff[] {
    return serviceById(catalog, serviceId)?.staff ?? [];
}
