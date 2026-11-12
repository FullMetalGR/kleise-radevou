import { describe, expect, it, vi } from 'vitest';
import type { Api } from './api';
import type { Fetch } from './config';
import { HOLDS_PATH } from './hold';
import { releasePath, sendRelease } from './release';

/*
 * The release over DELETE /holds/{uuid}: the token as the query parameter, since a DELETE
 * carries no body, best effort, the answer never read and a failure swallowed where it happens.
 */

const UUID = '7b7f0e5a-2222-4000-8000-00000000000a';
const TOKEN = 'f'.repeat(64);

function api(fetchFn: Fetch): Api {
    return { origin: 'https://luna.kleiseradevou.gr', fetch: fetchFn };
}

async function drain(): Promise<void> {
    for (let turn = 0; turn < 4; turn += 1) {
        await new Promise<void>((resolve) => setTimeout(resolve, 0));
    }
}

describe('the release path', () => {
    it('names the hold and carries the token as the one query parameter, both escaped', () => {
        expect(releasePath(UUID, TOKEN)).toBe(`${HOLDS_PATH}/${UUID}?token=${TOKEN}`);
        expect(releasePath('a/b', 'x&y=z')).toBe(`${HOLDS_PATH}/a%2Fb?token=x%26y%3Dz`);
    });
});

describe('sending a release', () => {
    it('sends one DELETE with no body and no credentials, the token in the query string alone, kept alive past the unload', () => {
        const fetchFn = vi.fn<Fetch>(() => Promise.resolve(new Response(null, { status: 204 })));

        sendRelease(api(fetchFn), UUID, TOKEN);

        expect(fetchFn).toHaveBeenCalledTimes(1);

        const [url, init] = fetchFn.mock.calls[0] ?? [];

        expect(url).toBe(`https://luna.kleiseradevou.gr${HOLDS_PATH}/${UUID}?token=${TOKEN}`);
        expect(init?.method).toBe('DELETE');
        expect(init?.credentials).toBe('omit');
        expect(init?.body).toBeUndefined();
        expect(init?.keepalive).toBe(true);
        expect(init?.headers).toEqual({ Accept: 'application/json' });
        expect(JSON.stringify(init?.headers ?? {})).not.toContain(TOKEN);
    });

    it('never reads the answer', async () => {
        const answer = new Response('{"data":{}}', { status: 404 });
        const read = vi.spyOn(answer, 'json');

        sendRelease(
            api(() => Promise.resolve(answer)),
            UUID,
            TOKEN,
        );
        await drain();

        expect(read).not.toHaveBeenCalled();
    });

    it('swallows a transport failure, so nothing reaches the page or the console', async () => {
        const unhandled = vi.fn();

        process.on('unhandledRejection', unhandled);

        try {
            sendRelease(
                api(() => Promise.reject(new TypeError('Failed to fetch'))),
                UUID,
                TOKEN,
            );
            await drain();
        } finally {
            process.off('unhandledRejection', unhandled);
        }

        expect(unhandled).not.toHaveBeenCalled();
    });

    it('swallows a fetch that throws before it returns a promise', () => {
        expect(() =>
            sendRelease(
                api(() => {
                    throw new TypeError('blocked');
                }),
                UUID,
                TOKEN,
            ),
        ).not.toThrow();
    });
});
