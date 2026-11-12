import { describe, expect, it } from 'vitest';
import { FILTER_SHAPE, NO_FILTERS } from '@/bookings/model';
import { readMemory, writeMemory } from './session-memory';

/*
 * Filter memory restores field by field with a shape check, and a browser blocking site
 * data costs the memory and never the screen.
 */

function storageOf(entries: Record<string, string>): () => Storage {
    const map = new Map(Object.entries(entries));

    return () =>
        ({
            getItem: (key: string) => map.get(key) ?? null,
            setItem: (key: string, value: string) => void map.set(key, value),
        }) as Storage;
}

const blocked = (): Storage => {
    throw new DOMException('The operation is insecure.', 'SecurityError');
};

describe('session memory', () => {
    it('restores each field that passes its check and falls back field by field where one does not', () => {
        const storage = storageOf({ bar: JSON.stringify({ from: '2026-11-01', status: 7, staff: 3, service: 'x', search: 'ada', page: 4 }) });

        expect(readMemory('bar', FILTER_SHAPE, NO_FILTERS, storage)).toEqual({ ...NO_FILTERS, from: '2026-11-01', staff: 3, search: 'ada' });
    });

    it('falls back to Any status for a saved status that is not one of the nine, and never reads a page back', () => {
        const restored = readMemory('bar', FILTER_SHAPE, NO_FILTERS, storageOf({ bar: JSON.stringify({ status: 'lost', page: 3 }) }));

        expect(restored.status).toBe('');
        expect(restored).not.toHaveProperty('page');
    });

    it('answers the defaults for nothing saved, for broken JSON and for a blocked storage, and swallows a blocked write', () => {
        expect(readMemory('bar', FILTER_SHAPE, NO_FILTERS, storageOf({}))).toEqual(NO_FILTERS);
        expect(readMemory('bar', FILTER_SHAPE, NO_FILTERS, storageOf({ bar: '{' }))).toEqual(NO_FILTERS);
        expect(readMemory('bar', FILTER_SHAPE, NO_FILTERS, blocked)).toEqual(NO_FILTERS);
        expect(() => writeMemory('bar', NO_FILTERS, blocked)).not.toThrow();
    });

    it('reads back what it wrote', () => {
        const storage = storageOf({});
        writeMemory('bar', { ...NO_FILTERS, status: 'awaiting_approval' }, storage);

        expect(readMemory('bar', FILTER_SHAPE, NO_FILTERS, storage).status).toBe('awaiting_approval');
    });
});
