import { QueryClient } from '@tanstack/react-query';
import type { Fetch } from './config';
import { pathUnder, withLanguage, type Language } from './language';
import type { LineKey, Values } from './words';

/*
 * The wire. Every request goes to the origin the boot derived from the script's own URL, under
 * the mount's credentials mode: omitted everywhere, since the API is public and answers
 * permissive CORS, but for My bookings' list on the hosted page (6.6), whose requests go to the
 * page's own origin with credentials included, so the browser carries the session cookie it holds
 * there. The app never reads that cookie and names no credential of it; it only chooses whether
 * the browser sends one. Every request carries the app's language once the config has named it:
 * as lang on the API, and as the prefix on the one page path the app reads, My bookings' list,
 * which takes its language from the prefix and reads no lang. Every failure is classified by
 * exactly one function into a notice, the server's sentence or one of the app's own by its key of
 * booking.php, and a politeness, and the retry predicate is the query client's default: no 4xx is
 * ever retried, a transport failure or a 5xx gets three attempts behind exponential backoff, and
 * a mutation never retries, because a blind second hold or confirm could act twice.
 */

/**
 * Whether the browser sends its cookies with a request: include in list mode on the hosted
 * origin alone, omit everywhere else, so the embed never sends a cookie.
 */
export type Credentials = 'include' | 'omit';

export interface Api {
    readonly origin: string;
    readonly fetch: Fetch;
    /** The mount's credentials mode, which the boot decides; absent means omit. */
    readonly credentials?: Credentials;
    /**
     * The language the app holds, the config's, sent on every request as lang; absent before the
     * config is in hand, when the server answers its default.
     */
    readonly language?: Language;
}

/**
 * The credentials mode every request of a mount is sent under.
 */
export function credentialsOf(api: Api): Credentials {
    return api.credentials ?? 'omit';
}

/**
 * A response with an error status. The detail is the envelope's sentence, written to be shown
 * verbatim, with the message as the fallback, and null when the body carried no sentence at all,
 * which the classifier answers with the app's own. The code is carried but the app branches on
 * the status alone.
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly detail: string | null,
        public readonly code: string | null = null,
    ) {
        super(detail ?? `The answer ${String(status)} carried no sentence.`);
        this.name = 'ApiError';
    }
}

export type Tone = 'polite' | 'alert';

/**
 * What the one notice region says: a sentence the server wrote, shown verbatim in the language it
 * was asked in, or one of the app's own by its key of booking.php and the values of its
 * placeholders, read in the language the app holds when it renders.
 */
export type Notice = { readonly text: string; readonly tone: Tone } | { readonly key: LineKey; readonly values?: Values; readonly tone: Tone };

/**
 * The fixed sentence for a failure that is not an answer: a transport drop or a parse failure.
 * The raw message is never shown.
 */
export const TRANSPORT_SENTENCE: LineKey = 'wire.transport';

/**
 * The sentence for an error status whose body carried no sentence to show.
 */
export const UNWORDED_SENTENCE: LineKey = 'wire.unworded';

export const MAX_ATTEMPTS = 3;

/**
 * What every request to the API asks for: JSON. Each request beside it is sent under the mount's
 * credentials mode, omit unless the mount is My bookings' list.
 */
const ASKS_FOR_JSON = { Accept: 'application/json' } as const;

/**
 * GET a JSON body from the API origin. A non-2xx status is an ApiError carrying the status and
 * the envelope's detail; a response that is not JSON is the parse error the runtime raises,
 * which is not an API error and classifies as one fixed sentence.
 */
export async function getJson(api: Api, path: string): Promise<unknown> {
    const response = await api.fetch(urlOf(api, path), { headers: ASKS_FOR_JSON, credentials: credentialsOf(api) });

    if (!response.ok) {
        throw await errorOf(response);
    }

    return response.json();
}

/**
 * POST a JSON body to the API origin and read the answer, classified exactly as a read is: a
 * non-2xx status is an ApiError carrying the status and the envelope's detail. The body is
 * JSON, which is what makes the embed's cross-origin write a preflighted one; the API answers
 * the preflight permissively.
 */
export async function postJson(api: Api, path: string, body: unknown): Promise<unknown> {
    const response = await api.fetch(urlOf(api, path), {
        method: 'POST',
        headers: { ...ASKS_FOR_JSON, 'Content-Type': 'application/json' },
        credentials: credentialsOf(api),
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw await errorOf(response);
    }

    return response.json();
}

/**
 * DELETE a path on the API origin, kept alive past the page going away as far as the platform
 * allows: a beacon cannot carry the method, and a keepalive fetch is the one request the
 * browser lets outlive the document that sent it. The answer is handed back unread, for a
 * caller that has no use for it.
 */
export function deleteKeptAlive(api: Api, path: string): Promise<Response> {
    return api.fetch(urlOf(api, path), { method: 'DELETE', headers: ASKS_FOR_JSON, credentials: credentialsOf(api), keepalive: true });
}

/** The paths the API answers under; every other path the app reads is a page's. */
const API_PATHS = '/api/';

/**
 * Where a request goes: the path on the API origin, the app's language beside its query on the
 * API and as its prefix on a page path.
 */
function urlOf(api: Api, path: string): string {
    if (path.startsWith(API_PATHS) || api.language === undefined) {
        return api.origin + withLanguage(path, api.language);
    }

    return api.origin + pathUnder(path, api.language);
}

async function errorOf(response: Response): Promise<ApiError> {
    let body: unknown = null;

    try {
        body = await response.json();
    } catch {
        body = null;
    }

    const envelope = readEnvelope(body);

    return new ApiError(response.status, envelope.detail ?? envelope.message, envelope.code);
}

interface Envelope {
    readonly code: string | null;
    readonly message: string | null;
    readonly detail: string | null;
}

/**
 * The envelope's three strings where the body carries them: code and message at the top,
 * detail inside data. Anything malformed is simply absent.
 */
function readEnvelope(body: unknown): Envelope {
    const record = asRecord(body);
    const data = asRecord(record?.['data']);

    return {
        code: asString(record?.['code']),
        message: asString(record?.['message']),
        detail: asString(data?.['detail']),
    };
}

function asRecord(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : null;
}

function asString(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * The classifier: not an API error is a fixed sentence and an alert; 500 or above is the
 * server's sentence and an alert; below 500 is the server's sentence, polite. A 4xx is a
 * worded answer, not an emergency. An answer that carried no sentence is the app's own
 * unworded one, in the same politeness.
 */
export function classify(error: unknown): Notice {
    if (!(error instanceof ApiError)) {
        return { key: TRANSPORT_SENTENCE, tone: 'alert' };
    }

    const tone = error.status >= 500 ? 'alert' : 'polite';

    return error.detail === null ? { key: UNWORDED_SENTENCE, tone } : { text: error.detail, tone };
}

/**
 * Whether a failure is the server's worded refusal rather than an accident on the way: a 4xx,
 * which carries a sentence written to be shown. It is one predicate because three places act
 * on the same distinction and they must not drift: no 4xx is ever retried, a 4xx presents
 * politely, and a 4xx read in manage mode is the neutral panel while anything else is the
 * retry panel. A transport drop and a 5xx are the other kind: nothing was decided, so nothing
 * has been answered.
 */
export function isRefusal(error: unknown): boolean {
    return error instanceof ApiError && error.status >= 400 && error.status < 500;
}

/**
 * The retry predicate.
 */
export function shouldRetry(failureCount: number, error: unknown): boolean {
    if (isRefusal(error)) {
        return false;
    }

    return failureCount < MAX_ATTEMPTS;
}

/**
 * One client per mount: the retry predicate as the default for every query, no retry for any
 * mutation, and the delay between attempts the client's own exponential backoff.
 */
export function createQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: { retry: shouldRetry },
            mutations: { retry: false },
        },
    });
}
