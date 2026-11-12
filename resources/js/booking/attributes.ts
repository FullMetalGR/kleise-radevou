import type { Credentials } from './api';

/*
 * The mount contract's attribute readers. These values come from a tenant's own template, so
 * each reader trims first and treats anything malformed as absent: a trailing space must not
 * drop a magic-link customer into the booking flow, and a stray character in an id must not
 * preselect a service nobody asked for. There is no tenant attribute and no key: the script's
 * own URL names the tenant. There is no source attribute either: the server stamps a
 * booking's source from the request's origin, and nothing here names its own.
 */

export type Mode = 'book' | 'manage' | 'list';

/**
 * The fixed list a hosted-page link is tagged from, ?ch= written onto the mount as
 * data-channel. The server keeps the same five and stores nothing else.
 */
export const CHANNELS = ['instagram', 'facebook', 'google', 'qr', 'other'] as const;

export type Channel = (typeof CHANNELS)[number];

export interface MountOptions {
    readonly mode: Mode;
    readonly serviceId: number | null;
    readonly staffId: number | null;
    readonly uuid: string | null;
    readonly token: string | null;
    readonly channel: Channel | null;
}

/**
 * `manage` only when the trimmed value is exactly that. `list`, My bookings, only when the
 * trimmed value is exactly that and the page is the hosted one, where the config source is the
 * inlined one and the page inlined the list beside it: a mount on any other page naming it is
 * `book`, so a tenant's own site can never open the list. Anything else, absent included, is
 * `book`.
 */
export function readMode(value: string | null, hosted = false): Mode {
    const trimmed = value?.trim() ?? '';

    if (trimmed === 'manage') {
        return 'manage';
    }

    return trimmed === 'list' && hosted ? 'list' : 'book';
}

/**
 * The credentials mode a mount's requests go under: include in list mode, which readMode answers
 * only on the hosted page beside the inlined list, on the script's own origin, so the browser
 * carries My bookings' session cookie to the tenant's own API; omit in every other mode, so the
 * embed and the hosted page's flow and manage view never send a cookie. The app only chooses the
 * mode and never reads the cookie (5.5).
 */
export function credentialsFor(mode: Mode): Credentials {
    return mode === 'list' ? 'include' : 'omit';
}

/**
 * A positive integer, or null. The value is trimmed and must be decimal digits and nothing
 * else: "", "0", "-3", "abc" and "12abc" all mean nothing preselected.
 */
export function readId(value: string | null): number | null {
    if (value === null) {
        return null;
    }

    const trimmed = value.trim();

    if (!/^[0-9]+$/.test(trimmed)) {
        return null;
    }

    const id = Number(trimmed);

    return id > 0 && Number.isSafeInteger(id) ? id : null;
}

/**
 * Trimmed; empty means null. A token is never verified here, only carried.
 */
export function readText(value: string | null): string | null {
    const trimmed = value?.trim() ?? '';

    return trimmed === '' ? null : trimmed;
}

/**
 * One of the fixed five, trimmed, or null: a tag outside the list is no tag, never an error,
 * since the hold drops it as well.
 */
export function readChannel(value: string | null): Channel | null {
    const trimmed = value?.trim() ?? '';

    return CHANNELS.find((channel) => channel === trimmed) ?? null;
}

/**
 * The mount's options. `hosted` says the list mode may be read: the config source is the inlined
 * one and the page inlined My bookings' list, which only the hosted page on the script's own
 * origin does.
 */
export function readMountOptions(el: Element, hosted = false): MountOptions {
    return {
        mode: readMode(el.getAttribute('data-mode'), hosted),
        serviceId: readId(el.getAttribute('data-service')),
        staffId: readId(el.getAttribute('data-staff')),
        uuid: readText(el.getAttribute('data-uuid')),
        token: readText(el.getAttribute('data-token')),
        channel: readChannel(el.getAttribute('data-channel')),
    };
}
