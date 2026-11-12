import { describe, expect, it } from 'vitest';
import { applyTheme, readTheme, THEME_KEY, writeTheme } from './theme';

/*
 * The theme remembered per machine, and the screen kept when the browser blocks site data.
 */

function memory(initial: Record<string, string> = {}): Storage {
    const items = new Map(Object.entries(initial));

    return {
        get length() {
            return items.size;
        },
        clear: () => items.clear(),
        getItem: (key) => items.get(key) ?? null,
        key: (index) => [...items.keys()][index] ?? null,
        removeItem: (key) => void items.delete(key),
        setItem: (key, value) => void items.set(key, value),
    };
}

const blocked = (): Storage => {
    throw new DOMException('The operation is insecure.', 'SecurityError');
};

describe('the theme', () => {
    it('reads a remembered dark theme and defaults to light', () => {
        expect(readTheme(() => memory({ [THEME_KEY]: 'dark' }))).toBe('dark');
        expect(readTheme(() => memory())).toBe('light');
        expect(readTheme(() => memory({ [THEME_KEY]: 'purple' }))).toBe('light');
    });

    it('remembers a choice', () => {
        const storage = memory();

        writeTheme('dark', () => storage);
        expect(storage.getItem(THEME_KEY)).toBe('dark');
    });

    it('costs the memory and never the screen when storage is blocked', () => {
        expect(readTheme(blocked)).toBe('light');
        expect(() => writeTheme('dark', blocked)).not.toThrow();

        const throwing = memory();
        throwing.setItem = () => {
            throw new DOMException('quota', 'QuotaExceededError');
        };
        expect(() => writeTheme('dark', () => throwing)).not.toThrow();
    });

    it('applies the theme as a class on the root element', () => {
        const root = document.createElement('html');

        applyTheme('dark', root);
        expect(root.classList.contains('dark')).toBe(true);

        applyTheme('light', root);
        expect(root.classList.contains('dark')).toBe(false);
    });
});
