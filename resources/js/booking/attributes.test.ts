import { describe, expect, it } from 'vitest';
import { CHANNELS, credentialsFor, readChannel, readId, readMode, readMountOptions, readText } from './attributes';

/*
 * The attribute readers, as a table: trimmed and untrimmed mode values, and zero, negative,
 * empty and non-numeric ids.
 */

describe('readMode', () => {
    it.each([
        ['manage', 'manage'],
        [' manage ', 'manage'],
        ['\tmanage\n', 'manage'],
        ['book', 'book'],
        [' book ', 'book'],
        ['Manage', 'book'],
        ['managed', 'book'],
        ['manage book', 'book'],
        ['', 'book'],
        ['   ', 'book'],
        [null, 'book'],
    ])('reads %j as %s', (value, expected) => {
        expect(readMode(value)).toBe(expected);
    });
});

describe('readMode on the hosted page', () => {
    it.each([
        ['list', true, 'list'],
        [' list ', true, 'list'],
        ['list', false, 'book'],
        [' list ', false, 'book'],
        ['List', true, 'book'],
        ['lists', true, 'book'],
        ['manage', true, 'manage'],
        [null, true, 'book'],
    ])('reads %j, the page hosted %s, as %s', (value, hosted, expected) => {
        expect(readMode(value, hosted as boolean)).toBe(expected);
    });

    it('reads list as book by default, as a mount on any page but the hosted one is read', () => {
        expect(readMode('list')).toBe('book');
    });
});

describe('readId', () => {
    it.each([
        ['12', 12],
        [' 4 ', 4],
        ['1', 1],
        ['0', null],
        ['-3', null],
        ['', null],
        ['   ', null],
        ['abc', null],
        ['12abc', null],
        ['1.5', null],
        ['+5', null],
        ['1e3', null],
        ['00', null],
        ['007', 7],
        [null, null],
    ])('reads %j as %j', (value, expected) => {
        expect(readId(value)).toBe(expected);
    });
});

describe('readText', () => {
    it.each([
        ['abc', 'abc'],
        ['  abc  ', 'abc'],
        ['', null],
        ['   ', null],
        [null, null],
    ])('reads %j as %j', (value, expected) => {
        expect(readText(value)).toBe(expected);
    });
});

describe('readMountOptions', () => {
    it('reads the five attributes off the mount element', () => {
        const el = document.createElement('div');

        el.setAttribute('data-mode', ' manage ');
        el.setAttribute('data-service', '12');
        el.setAttribute('data-staff', '4');
        el.setAttribute('data-uuid', ' 0f2b ');
        el.setAttribute('data-token', 'tok ');
        el.setAttribute('data-channel', ' instagram ');

        expect(readMountOptions(el)).toEqual({ mode: 'manage', serviceId: 12, staffId: 4, uuid: '0f2b', token: 'tok', channel: 'instagram' });
    });

    it('reads list off the mount only where the page is the hosted one, the five attributes unchanged', () => {
        const el = document.createElement('div');

        el.setAttribute('data-mode', 'list');

        expect(readMountOptions(el, true).mode).toBe('list');
        expect(readMountOptions(el).mode).toBe('book');
        expect(Object.keys(readMountOptions(el, true)).sort()).toEqual(['channel', 'mode', 'serviceId', 'staffId', 'token', 'uuid']);
    });

    it('includes credentials for the list mode alone, which it reads only where the page is the hosted one, and omits them for every other mount', () => {
        const mount = (mode: string | null): Element => {
            const el = document.createElement('div');

            if (mode !== null) {
                el.setAttribute('data-mode', mode);
            }

            return el;
        };

        expect(credentialsFor(readMountOptions(mount('list'), true).mode)).toBe('include');
        expect(credentialsFor(readMountOptions(mount('list')).mode)).toBe('omit');
        expect(credentialsFor(readMountOptions(mount('manage'), true).mode)).toBe('omit');
        expect(credentialsFor(readMountOptions(mount('book'), true).mode)).toBe('omit');
        expect(credentialsFor(readMountOptions(mount(null), true).mode)).toBe('omit');
    });

    it('defaults everything for a bare mount element', () => {
        expect(readMountOptions(document.createElement('div'))).toEqual({
            mode: 'book',
            serviceId: null,
            staffId: null,
            uuid: null,
            token: null,
            channel: null,
        });
    });

    it('reads no tenant attribute and no key: the script URL names the tenant', () => {
        const el = document.createElement('div');

        el.setAttribute('data-tenant', 'somebody-else');
        el.setAttribute('data-key', 'not-a-thing');

        const options = readMountOptions(el);

        expect(Object.keys(options).sort()).toEqual(['channel', 'mode', 'serviceId', 'staffId', 'token', 'uuid']);
        expect(JSON.stringify(options)).not.toContain('somebody-else');
        expect(JSON.stringify(options)).not.toContain('not-a-thing');
    });
});

describe('readChannel', () => {
    it.each([
        ['instagram', 'instagram'],
        ['facebook', 'facebook'],
        ['google', 'google'],
        ['qr', 'qr'],
        ['other', 'other'],
        [' qr ', 'qr'],
        ['Instagram', null],
        ['tiktok', null],
        ['', null],
        ['   ', null],
        [null, null],
    ])('reads %j as %j: one of the fixed five, trimmed, or nothing', (value, expected) => {
        expect(readChannel(value)).toBe(expected);
    });

    it('keeps exactly the five the server stores', () => {
        expect(CHANNELS).toEqual(['instagram', 'facebook', 'google', 'qr', 'other']);
    });
});
