import { parseLanguage, withLanguage, type Language } from './language';

/*
 * The boot config: the seven keys both surfaces converge on. The hosted page inlines them in a
 * JSON script element, so its first render needs no round trip; the embed fetches them from the
 * origin its own script URL names, asking with the host page's language as lang where the page
 * names one. Two of the seven are languages: the tenant default, and the request's language as the
 * server resolved it, which is the language the app holds and sends on every call. Nothing about
 * packaging is ever in this payload, so nothing about it is read here.
 *
 * The business page inlines an eighth key where a My bookings session is open, customer: the name,
 * the email and the phone the details step starts from (2.4). It is read off the inlined config
 * alone and never off the API's answer, so the embed's config never carries one whatever the wire
 * says, and a malformed one is no prefill rather than no config.
 */

/** The customer the details step starts from: the session's most recent booking's. */
export interface Prefill {
    readonly name: string;
    readonly email: string;
    readonly phone: string | null;
}

export interface BootConfig {
    readonly tenantName: string;
    readonly timezone: string;
    readonly currency: string;
    readonly granularityMin: number;
    /** The owner's Important info, which the review step carries above its action; null where unset. */
    readonly importantInfo: string | null;
    /** The tenant default language. */
    readonly defaultLanguage: Language;
    /** The request's language as the server resolved it: the language the app holds and passes on every call. */
    readonly language: Language;
    /** The prefill of 2.4, on the hosted page's inlined config alone; absent everywhere else. */
    readonly customer?: Prefill;
}

export const CONFIG_PATH = '/api/v1/config';

export const INLINED_CONFIG_ID = 'kr-config';

export type Fetch = (input: string, init: RequestInit) => Promise<Response>;

/**
 * The API origin, read off the script element that is executing: document.currentScript at
 * import time. Its src is already absolute, and the origin carries the tenant, so there is no
 * tenant attribute and no key. Null when there is no such element or it names no origin, as
 * for a script evaluated inline.
 */
export function apiOriginOf(script: unknown): string | null {
    const src = (script as { src?: unknown } | null)?.src;

    if (typeof src !== 'string' || src === '') {
        return null;
    }

    try {
        const origin = new URL(src).origin;

        return origin === 'null' ? null : origin;
    } catch {
        return null;
    }
}

/**
 * The payload as the wire carries it, checked key by key. Anything else is not a config.
 */
export function parseConfig(value: unknown): BootConfig | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const record = value as Record<string, unknown>;
    const tenantName = record['tenant_name'];
    const timezone = record['timezone'];
    const currency = record['currency'];
    const granularityMin = record['granularity_min'];
    const importantInfo = record['important_info'];
    const defaultLanguage = parseLanguage(record['default_language']);
    const language = parseLanguage(record['language']);

    if (typeof tenantName !== 'string' || typeof timezone !== 'string' || typeof currency !== 'string') {
        return null;
    }

    if (defaultLanguage === null || language === null) {
        return null;
    }

    if (typeof granularityMin !== 'number' || !Number.isInteger(granularityMin) || granularityMin <= 0) {
        return null;
    }

    if (importantInfo !== null && typeof importantInfo !== 'string') {
        return null;
    }

    return { tenantName, timezone, currency, granularityMin, importantInfo, defaultLanguage, language };
}

/**
 * The prefill the business page inlined beside the seven keys, or null where there is none or it
 * is not a customer: a name and an email that are non-empty strings, and a phone that is one or
 * null.
 */
export function parsePrefill(value: unknown): Prefill | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const record = value as Record<string, unknown>;
    const name = record['name'];
    const email = record['email'];
    const phone = record['phone'];

    if (typeof name !== 'string' || name === '' || typeof email !== 'string' || email === '' || (phone !== null && typeof phone !== 'string')) {
        return null;
    }

    return { name, email, phone };
}

/**
 * The config the hosted page inlined, or null when the document carries none that parses, with
 * the prefill of 2.4 where the page inlined one.
 */
export function inlinedConfig(doc: Document): BootConfig | null {
    const el = doc.getElementById(INLINED_CONFIG_ID);

    if (el === null || el.tagName !== 'SCRIPT' || el.getAttribute('type') !== 'application/json') {
        return null;
    }

    try {
        const inlined: unknown = JSON.parse(el.textContent ?? '');
        const config = parseConfig(inlined);
        const customer = parsePrefill((inlined as Record<string, unknown> | null)?.['customer']);

        return config === null || customer === null ? config : { ...config, customer };
    } catch {
        return null;
    }
}

/**
 * GET /api/v1/config on the API origin, asked in the start language where there is one.
 * Cross-origin by design, without credentials: the endpoint is public and answers permissive
 * CORS.
 */
export async function fetchConfig(apiOrigin: string, fetchFn: Fetch, start: Language | null = null): Promise<BootConfig> {
    const url = apiOrigin + withLanguage(CONFIG_PATH, start);
    const response = await fetchFn(url, { headers: { Accept: 'application/json' }, credentials: 'omit' });

    if (!response.ok) {
        throw new Error(`GET ${url} answered ${response.status}.`);
    }

    const config = parseConfig(await response.json());

    if (config === null) {
        throw new Error(`GET ${url} answered something other than the boot config.`);
    }

    return config;
}
