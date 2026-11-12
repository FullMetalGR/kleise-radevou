import { describe, expect, it } from 'vitest';
import { adoptRegistry, REGISTRY_KEY } from './registry';

/*
 * The global registry: adopted with an instanceof check, so a clobbered global is replaced
 * rather than kept and thrown on.
 */

describe('adoptRegistry', () => {
    it('creates the WeakSet on the global when there is none', () => {
        const host: Record<string, unknown> = {};
        const registry = adoptRegistry(host);

        expect(registry).toBeInstanceOf(WeakSet);
        expect(host[REGISTRY_KEY]).toBe(registry);
    });

    it('adopts an existing WeakSet with its members, across executions', () => {
        const host: Record<string, unknown> = {};
        const el = document.createElement('div');

        adoptRegistry(host).add(el);

        const again = adoptRegistry(host);

        expect(again).toBe(host[REGISTRY_KEY]);
        expect(again.has(el)).toBe(true);
    });

    it.each([
        ['a string', 'junk'],
        ['a plain object', {}],
        ['a Set', new Set()],
        ['null', null],
        ['a number', 42],
        ['an array', []],
    ])('replaces %s another script left on the global', (_label, junk) => {
        const host: Record<string, unknown> = { [REGISTRY_KEY]: junk };
        const registry = adoptRegistry(host);

        expect(registry).toBeInstanceOf(WeakSet);
        expect(host[REGISTRY_KEY]).toBe(registry);
        expect(() => registry.has(document.createElement('div'))).not.toThrow();
    });
});
