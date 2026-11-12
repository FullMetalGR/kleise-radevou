import { describe, expect, it, vi } from 'vitest';
import { apiOriginOf, CONFIG_PATH, fetchConfig, inlinedConfig, INLINED_CONFIG_ID, parseConfig, parsePrefill, type Fetch } from './config';

const WIRE = { tenant_name: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularity_min: 5, important_info: null, default_language: 'en', language: 'en' };
const PARSED = { tenantName: 'Luna Hair Studio', timezone: 'Europe/Athens', currency: 'EUR', granularityMin: 5, importantInfo: null, defaultLanguage: 'en', language: 'en' };

function scriptWithSrc(src: string): HTMLScriptElement {
    const script = document.createElement('script');

    script.src = src;

    return script;
}

function answering(body: unknown, status = 200): Fetch {
    return () =>
        Promise.resolve(
            new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
        );
}

describe('apiOriginOf', () => {
    it('is the origin of the script URL, tenant subdomain included', () => {
        expect(apiOriginOf(scriptWithSrc('https://salon-x.kleiseradevou.gr/embed.js'))).toBe('https://salon-x.kleiseradevou.gr');
        expect(apiOriginOf(scriptWithSrc('http://luna.localhost:8000/embed.js?v=2'))).toBe('http://luna.localhost:8000');
    });

    it('is null when there is no executing script, or it names no origin', () => {
        expect(apiOriginOf(null)).toBeNull();
        expect(apiOriginOf(undefined)).toBeNull();
        expect(apiOriginOf(document.createElement('script'))).toBeNull();
        expect(apiOriginOf({ src: 'data:text/javascript,1' })).toBeNull();
        expect(apiOriginOf({ src: 42 })).toBeNull();
    });
});

describe('parseConfig', () => {
    it('reads the seven keys off the wire shape, important_info null where the owner set none', () => {
        expect(parseConfig(WIRE)).toEqual(PARSED);
    });

    it('carries the tenant default and the request\'s language apart, each the code the server named', () => {
        expect(parseConfig({ ...WIRE, default_language: 'el', language: 'en' })).toEqual({ ...PARSED, defaultLanguage: 'el', language: 'en' });
        expect(parseConfig({ ...WIRE, default_language: 'en', language: 'el' })).toEqual({ ...PARSED, defaultLanguage: 'en', language: 'el' });
    });

    it('carries the important info where the owner set it', () => {
        expect(parseConfig({ ...WIRE, important_info: 'Please arrive five minutes early.' })).toEqual({ ...PARSED, importantInfo: 'Please arrive five minutes early.' });
    });

    it.each([
        ['null', null],
        ['a string', 'config'],
        ['an empty object', {}],
        ['a missing name', { ...WIRE, tenant_name: undefined }],
        ['a numeric name', { ...WIRE, tenant_name: 7 }],
        ['a string granularity', { ...WIRE, granularity_min: '5' }],
        ['a zero granularity', { ...WIRE, granularity_min: 0 }],
        ['a fractional granularity', { ...WIRE, granularity_min: 2.5 }],
        ['a missing important info', { ...WIRE, important_info: undefined }],
        ['a numeric important info', { ...WIRE, important_info: 7 }],
        ['a missing default language', { ...WIRE, default_language: undefined }],
        ['a default language neither', { ...WIRE, default_language: 'fr' }],
        ['a missing language', { ...WIRE, language: undefined }],
        ['a language with its region', { ...WIRE, language: 'el-GR' }],
    ])('refuses %s', (_label, value) => {
        expect(parseConfig(value)).toBeNull();
    });

    it('ignores an eighth key rather than carrying it, the customer included: the API\'s config never prefills', () => {
        expect(parseConfig({ ...WIRE, entitlements: ['staff'] })).toEqual(PARSED);
        expect(parseConfig({ ...WIRE, customer: { name: 'Casey Customer', email: 'casey@example.com', phone: null } })).toEqual(PARSED);
    });
});

describe('the prefill of 2.4, on the inlined config alone', () => {
    const CUSTOMER = { name: 'Casey Customer', email: 'casey@example.com', phone: '+30 210 0000000' };

    function inline(payload: unknown): void {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(payload)}</script>`;
    }

    it('reads the customer the business page inlined beside the seven keys under a session', () => {
        inline({ ...WIRE, customer: CUSTOMER });

        expect(inlinedConfig(document)).toEqual({ ...PARSED, customer: CUSTOMER });
    });

    it('carries a null phone as null', () => {
        inline({ ...WIRE, customer: { ...CUSTOMER, phone: null } });

        expect(inlinedConfig(document)?.customer).toEqual({ ...CUSTOMER, phone: null });
    });

    it('reads no customer where the page inlined none, and the seven keys as before', () => {
        inline(WIRE);

        expect(inlinedConfig(document)).toEqual(PARSED);
        expect(inlinedConfig(document)).not.toHaveProperty('customer');
    });

    it.each([
        ['null', null],
        ['a string', 'Casey'],
        ['a missing name', { ...CUSTOMER, name: undefined }],
        ['an empty name', { ...CUSTOMER, name: '' }],
        ['a missing email', { ...CUSTOMER, email: undefined }],
        ['a numeric phone', { ...CUSTOMER, phone: 210 }],
        ['a missing phone', { name: CUSTOMER.name, email: CUSTOMER.email }],
    ])('treats %s as no prefill and keeps the config', (_label, customer) => {
        expect(parsePrefill(customer)).toBeNull();

        inline({ ...WIRE, customer });

        expect(inlinedConfig(document)).toEqual(PARSED);
    });

    it('never carries one off the API, whatever the wire says', async () => {
        await expect(fetchConfig('https://salon-x.kleiseradevou.gr', answering({ ...WIRE, customer: CUSTOMER }))).resolves.toEqual(PARSED);
    });
});

describe('inlinedConfig', () => {
    it('reads the JSON script element the hosted page inlines', () => {
        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script>`;

        expect(inlinedConfig(document)).toEqual(PARSED);
    });

    it('is null without the element, with the wrong type, or with a payload that is not a config', () => {
        document.body.innerHTML = '';
        expect(inlinedConfig(document)).toBeNull();

        document.body.innerHTML = `<script id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</script>`;
        expect(inlinedConfig(document)).toBeNull();

        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">{not json</script>`;
        expect(inlinedConfig(document)).toBeNull();

        document.body.innerHTML = `<script type="application/json" id="${INLINED_CONFIG_ID}">{"tenant_name":"x"}</script>`;
        expect(inlinedConfig(document)).toBeNull();

        document.body.innerHTML = `<div id="${INLINED_CONFIG_ID}">${JSON.stringify(WIRE)}</div>`;
        expect(inlinedConfig(document)).toBeNull();
    });
});

describe('fetchConfig', () => {
    it('GETs /api/v1/config on the API origin, asking for JSON and sending no credentials', async () => {
        const fetchFn = vi.fn(answering(WIRE));

        await expect(fetchConfig('https://salon-x.kleiseradevou.gr', fetchFn)).resolves.toEqual(PARSED);

        expect(fetchFn).toHaveBeenCalledTimes(1);
        expect(fetchFn).toHaveBeenCalledWith(`https://salon-x.kleiseradevou.gr${CONFIG_PATH}`, {
            headers: { Accept: 'application/json' },
            credentials: 'omit',
        });
    });

    it('asks in the start language as lang where there is one, and with none where there is none', async () => {
        const fetchFn = vi.fn(answering({ ...WIRE, language: 'el' }));

        await expect(fetchConfig('https://salon-x.kleiseradevou.gr', fetchFn, 'el')).resolves.toEqual({ ...PARSED, language: 'el' });
        await fetchConfig('https://salon-x.kleiseradevou.gr', fetchFn, null);

        expect(fetchFn.mock.calls.map(([url]) => url)).toEqual([`https://salon-x.kleiseradevou.gr${CONFIG_PATH}?lang=el`, `https://salon-x.kleiseradevou.gr${CONFIG_PATH}`]);
    });

    it('rejects an answer that is not ok, naming the status', async () => {
        await expect(fetchConfig('https://salon-x.kleiseradevou.gr', answering({ message: 'gone' }, 404))).rejects.toThrow('404');
    });

    it('rejects an answer that is not the boot config', async () => {
        await expect(fetchConfig('https://salon-x.kleiseradevou.gr', answering({ tenant_name: 'x' }))).rejects.toThrow('boot config');
    });
});
