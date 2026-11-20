import { afterEach, describe, expect, it } from 'vitest';
import { COLLAPSED_KEY, readCollapsed, writeCollapsed } from './collapsed';

/*
 * The sidebar's collapsed choice, remembered on the device, and the sidebar kept open and working
 * when the browser blocks site data.
 */

const local = (): Storage => window.localStorage;

const blocked = (): Storage => {
    throw new DOMException('The operation is insecure.', 'SecurityError');
};

/** A storage that is reached but refuses every read and write, as a full or locked one does. */
const refusing = (): Storage =>
    ({
        getItem: () => {
            throw new DOMException('The operation is insecure.', 'SecurityError');
        },
        setItem: () => {
            throw new DOMException('quota', 'QuotaExceededError');
        },
    }) as unknown as Storage;

afterEach(() => window.localStorage.clear());

describe('the sidebar\'s collapsed choice', () => {
    it('starts open where nothing is remembered', () => {
        expect(readCollapsed(local)).toBe(false);
    });

    it('remembers a collapse and an expand under its own key', () => {
        writeCollapsed(true, local);
        expect(readCollapsed(local)).toBe(true);
        expect(window.localStorage.getItem(COLLAPSED_KEY)).not.toBeNull();

        writeCollapsed(false, local);
        expect(readCollapsed(local)).toBe(false);
    });

    it('reads anything but its own remembered collapse as open', () => {
        window.localStorage.setItem(COLLAPSED_KEY, 'sideways');

        expect(readCollapsed(local)).toBe(false);
    });

    it('starts open where reaching the storage throws, or reading from it does', () => {
        expect(readCollapsed(blocked)).toBe(false);
        expect(readCollapsed(refusing)).toBe(false);
    });

    it('swallows a write the browser refuses, so the choice still holds for the visit', () => {
        expect(() => writeCollapsed(true, blocked)).not.toThrow();
        expect(() => writeCollapsed(true, refusing)).not.toThrow();
    });
});
