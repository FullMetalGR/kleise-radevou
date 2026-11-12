import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, classify, createQueryClient, credentialsOf, deleteKeptAlive, getJson, MAX_ATTEMPTS, postJson, shouldRetry, TRANSPORT_SENTENCE, UNWORDED_SENTENCE, type Api } from './api';
import type { Fetch } from './config';

/*
 * The wire rules: the request shape, the error type off the envelope, the classifier's three
 * branches, the retry predicate on its own and as the client's default, and mutations never
 * retrying.
 */

const ORIGIN = 'https://salon-x.kleiseradevou.gr';

function answering(body: unknown, status = 200): Fetch {
    return () => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

function envelope(status: number, detail: string, code = 'some_code'): unknown {
    return { code, message: code, data: { status, detail } };
}

function api(fetchFn: Fetch): Api {
    return { origin: ORIGIN, fetch: fetchFn };
}

afterEach(() => {
    vi.useRealTimers();
});

describe('getJson', () => {
    it('GETs the path on the API origin, asking for JSON and sending no credentials', async () => {
        const fetchFn = vi.fn(answering({ ok: 1 }));

        await expect(getJson(api(fetchFn), '/api/v1/services')).resolves.toEqual({ ok: 1 });

        expect(fetchFn).toHaveBeenCalledTimes(1);
        expect(fetchFn).toHaveBeenCalledWith(`${ORIGIN}/api/v1/services`, { headers: { Accept: 'application/json' }, credentials: 'omit' });
    });

    it('raises an ApiError with the status and the envelope detail on an error status', async () => {
        const failure = await getJson(api(answering(envelope(429, 'Too many requests. Please wait a moment.', 'rate_limited'), 429)), '/x').catch((error: unknown) => error);

        expect(failure).toBeInstanceOf(ApiError);
        expect(failure).toMatchObject({ status: 429, detail: 'Too many requests. Please wait a moment.', code: 'rate_limited' });
        expect((failure as Error).message).toBe('Too many requests. Please wait a moment.');
    });

    it('falls back to the message, then to no sentence, which the classifier answers with the app\'s own, when the body carries no detail', async () => {
        const withMessage = await getJson(api(answering({ code: 'not_found', message: 'Not found.' }, 404)), '/x').catch((error: unknown) => error);
        const noEnvelope = await getJson(api(() => Promise.resolve(new Response('<html>Bad gateway</html>', { status: 502 }))), '/x').catch((error: unknown) => error);
        const emptyDetail = await getJson(api(answering({ code: 'x', message: '', data: { detail: '' } }, 400)), '/x').catch((error: unknown) => error);

        expect(withMessage).toMatchObject({ status: 404, detail: 'Not found.', code: 'not_found' });
        expect(noEnvelope).toMatchObject({ status: 502, detail: null, code: null });
        expect(emptyDetail).toMatchObject({ status: 400, detail: null });
        expect(classify(noEnvelope)).toEqual({ key: UNWORDED_SENTENCE, tone: 'alert' });
        expect(classify(emptyDetail)).toEqual({ key: UNWORDED_SENTENCE, tone: 'polite' });
    });

    it('raises the parse error, not an ApiError, when a 200 body is not JSON', async () => {
        const failure = await getJson(api(() => Promise.resolve(new Response('not json', { status: 200 }))), '/x').catch((error: unknown) => error);

        expect(failure).toBeInstanceOf(Error);
        expect(failure).not.toBeInstanceOf(ApiError);
    });

    it('raises the transport error as it came', async () => {
        const dropped = new TypeError('Failed to fetch');

        await expect(getJson(api(() => Promise.reject(dropped)), '/x')).rejects.toBe(dropped);
    });
});

describe('the language on every request', () => {
    it('appends the language the api holds as lang to the read, the write and the release, beside a query a path carries', async () => {
        const fetchFn = vi.fn(answering({ ok: 1 }));
        const spoken: Api = { ...api(fetchFn), language: 'el' };

        await getJson(spoken, '/api/v1/services');
        await getJson(spoken, '/api/v1/bookings/x?token=abc');
        await postJson(spoken, '/api/v1/holds', {});
        await deleteKeptAlive(spoken, '/api/v1/holds/x?token=abc');

        expect(fetchFn.mock.calls.map(([url]) => url)).toEqual([
            `${ORIGIN}/api/v1/services?lang=el`,
            `${ORIGIN}/api/v1/bookings/x?token=abc&lang=el`,
            `${ORIGIN}/api/v1/holds?lang=el`,
            `${ORIGIN}/api/v1/holds/x?token=abc&lang=el`,
        ]);
    });

    it('puts the language as the prefix of a page path, which reads no lang, My bookings\' list read', async () => {
        const fetchFn = vi.fn(answering({ ok: 1 }));

        await getJson({ ...api(fetchFn), language: 'el' }, '/my-bookings');
        await getJson({ ...api(fetchFn), language: 'en' }, '/my-bookings');
        await getJson(api(fetchFn), '/my-bookings');

        expect(fetchFn.mock.calls.map(([url]) => url)).toEqual([`${ORIGIN}/el/my-bookings`, `${ORIGIN}/en/my-bookings`, `${ORIGIN}/my-bookings`]);
    });

    it('appends nothing where the api holds no language yet, before the config names one', async () => {
        const fetchFn = vi.fn(answering({ ok: 1 }));

        await getJson(api(fetchFn), '/api/v1/services');
        await postJson(api(fetchFn), '/api/v1/holds', {});
        await deleteKeptAlive(api(fetchFn), '/api/v1/holds/x?token=abc');

        expect(fetchFn.mock.calls.map(([url]) => url)).toEqual([`${ORIGIN}/api/v1/services`, `${ORIGIN}/api/v1/holds`, `${ORIGIN}/api/v1/holds/x?token=abc`]);
    });
});

describe('the credentials mode', () => {
    it('omits credentials where the mount names no mode, as every mount but My bookings\' list does', () => {
        expect(credentialsOf(api(answering({})))).toBe('omit');
        expect(credentialsOf({ ...api(answering({})), credentials: 'omit' })).toBe('omit');
    });

    it('sends every request of a mount under its mode: the read, the write and the release alike', async () => {
        for (const mode of ['include', 'omit'] as const) {
            const fetchFn = vi.fn(answering({ ok: 1 }));
            const mounted: Api = { ...api(fetchFn), credentials: mode };

            await getJson(mounted, '/my-bookings');
            await postJson(mounted, '/api/v1/bookings/b/cancel', {});
            await deleteKeptAlive(mounted, '/api/v1/holds/b');

            expect(fetchFn.mock.calls.map(([, init]) => init.credentials)).toEqual([mode, mode, mode]);
        }
    });
});

describe('classify', () => {
    it('turns a transport or parse failure into the fixed sentence and an alert, never the raw message', () => {
        expect(classify(new TypeError('Failed to fetch'))).toEqual({ key: TRANSPORT_SENTENCE, tone: 'alert' });
        expect(classify(new SyntaxError('Unexpected token <'))).toEqual({ key: TRANSPORT_SENTENCE, tone: 'alert' });
        expect(classify('junk')).toEqual({ key: TRANSPORT_SENTENCE, tone: 'alert' });
        expect(classify(undefined)).toEqual({ key: TRANSPORT_SENTENCE, tone: 'alert' });
    });

    it('turns a 5xx into the server sentence and an alert', () => {
        expect(classify(new ApiError(500, 'Something went wrong on our side.'))).toEqual({ text: 'Something went wrong on our side.', tone: 'alert' });
        expect(classify(new ApiError(503, 'Down for maintenance.')).tone).toBe('alert');
    });

    it('turns anything below 500 into the server sentence, politely', () => {
        expect(classify(new ApiError(409, 'That time was just taken. Please pick another.'))).toEqual({ text: 'That time was just taken. Please pick another.', tone: 'polite' });
        expect(classify(new ApiError(400, 'The email address is not valid.')).tone).toBe('polite');
        expect(classify(new ApiError(429, 'Too many requests.')).tone).toBe('polite');
        expect(classify(new ApiError(499, 'Closed.')).tone).toBe('polite');
    });
});

describe('shouldRetry', () => {
    it.each([400, 403, 404, 409, 410, 429, 499])('never retries a %d', (status) => {
        expect(shouldRetry(0, new ApiError(status, 'no'))).toBe(false);
    });

    it('retries a transport failure up to three times', () => {
        const dropped = new TypeError('Failed to fetch');

        expect(shouldRetry(0, dropped)).toBe(true);
        expect(shouldRetry(1, dropped)).toBe(true);
        expect(shouldRetry(2, dropped)).toBe(true);
        expect(shouldRetry(3, dropped)).toBe(false);
        expect(MAX_ATTEMPTS).toBe(3);
    });

    it('retries a 5xx up to three times', () => {
        const down = new ApiError(503, 'Down.');

        expect([0, 1, 2].map((count) => shouldRetry(count, down))).toEqual([true, true, true]);
        expect(shouldRetry(3, down)).toBe(false);
    });
});

describe('createQueryClient', () => {
    it('makes the predicate the default for queries and never retries a mutation', () => {
        const client = createQueryClient();
        const defaults = client.getDefaultOptions();

        expect(defaults.queries?.retry).toBe(shouldRetry);
        expect(defaults.mutations?.retry).toBe(false);
    });

    it('retries a transport failure three times behind backoff, then fails', async () => {
        vi.useFakeTimers();

        const fetchFn = vi.fn<Fetch>(() => Promise.reject(new TypeError('Failed to fetch')));
        const client = createQueryClient();
        const outcome = client.fetchQuery({ queryKey: ['probe'], queryFn: () => getJson(api(fetchFn), '/x') }).then(
            () => 'resolved',
            (error: unknown) => error,
        );

        await vi.advanceTimersByTimeAsync(0);
        expect(fetchFn).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(999);
        expect(fetchFn).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchFn).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(2000);
        expect(fetchFn).toHaveBeenCalledTimes(3);

        await vi.advanceTimersByTimeAsync(4000);
        expect(fetchFn).toHaveBeenCalledTimes(4);

        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetchFn).toHaveBeenCalledTimes(4);
        expect(await outcome).toBeInstanceOf(TypeError);

        client.clear();
    });

    it('fails a 4xx at once with no second request', async () => {
        vi.useFakeTimers();

        const fetchFn = vi.fn(answering(envelope(404, 'Not here.'), 404));
        const client = createQueryClient();
        const outcome = client.fetchQuery({ queryKey: ['probe'], queryFn: () => getJson(api(fetchFn), '/x') }).then(
            () => 'resolved',
            (error: unknown) => error,
        );

        await vi.advanceTimersByTimeAsync(60_000);

        expect(fetchFn).toHaveBeenCalledTimes(1);
        expect(await outcome).toMatchObject({ status: 404 });

        client.clear();
    });

    it('sends a mutation once whatever the failure', async () => {
        vi.useFakeTimers();

        const attempt = vi.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        const client = createQueryClient();
        const outcome = client.getMutationCache().build(client, { mutationFn: attempt }).execute(undefined).then(
            () => 'resolved',
            (error: unknown) => error,
        );

        await vi.advanceTimersByTimeAsync(60_000);

        expect(attempt).toHaveBeenCalledTimes(1);
        expect(await outcome).toBeInstanceOf(TypeError);

        client.clear();
    });
});
