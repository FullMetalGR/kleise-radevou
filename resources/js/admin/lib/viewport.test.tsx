import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { desktopWidth, mount, type Mounted, onPhone } from '@/components/ui/harness';
import { BREAKPOINT, useDesktop } from './viewport';

/*
 * The admin's one breakpoint: a token the stylesheet declares once, read off the root by its
 * name, never a number spelled in a component. At or above it the admin is the desktop layout it
 * always was; below it, the phone layout. Where the token cannot be read or the environment
 * answers no media query, the answer is the desktop, so nothing that cannot measure the viewport
 * is ever handed the phone layout by accident.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function Probe() {
    return <p className="probe">{useDesktop() ? 'desktop' : 'phone'}</p>;
}

const answer = () => view.host.querySelector('.probe')?.textContent;

type Listener = () => void;

/** A media query that answers what it is told and tells its listeners when that changes. */
function stubQueries(matches: boolean): { queries: string[]; set: (next: boolean) => void; undo: () => void } {
    const queries: string[] = [];
    const listeners = new Set<Listener>();
    let current = matches;
    const original = window.matchMedia;

    window.matchMedia = (query: string) => {
        queries.push(query);

        return {
            get matches() {
                return current;
            },
            media: query,
            addEventListener: (_type: string, listener: Listener) => listeners.add(listener),
            removeEventListener: (_type: string, listener: Listener) => listeners.delete(listener),
        } as unknown as MediaQueryList;
    };
    document.documentElement.style.setProperty(BREAKPOINT, desktopWidth());

    return {
        queries,
        set: (next) => {
            current = next;
            act(() => listeners.forEach((listener) => listener()));
        },
        undo: () => {
            window.matchMedia = original;
            document.documentElement.style.removeProperty(BREAKPOINT);
        },
    };
}

describe('the breakpoint', () => {
    it('answers desktop where the environment answers no media query', () => {
        view.render(<Probe />);

        expect(answer()).toBe('desktop');
    });

    it('answers desktop where the stylesheet has not declared the token', () => {
        const stub = stubQueries(false);
        document.documentElement.style.removeProperty(BREAKPOINT);

        view.render(<Probe />);

        expect(answer()).toBe('desktop');
        stub.undo();
    });

    it('asks for the width at or above the token\'s own value, read off the root by name', () => {
        const stub = stubQueries(true);

        view.render(<Probe />);

        expect(answer()).toBe('desktop');
        expect(stub.queries).toContain(`(min-width: ${desktopWidth()})`);
        stub.undo();
    });

    it('answers phone below it, and follows the viewport across the line both ways', () => {
        const stub = stubQueries(false);

        view.render(<Probe />);
        expect(answer()).toBe('phone');

        stub.set(true);
        expect(answer()).toBe('desktop');

        stub.set(false);
        expect(answer()).toBe('phone');
        stub.undo();
    });

    it('is what the harness puts the admin on for a phone test, and undone by it', () => {
        const undo = onPhone();

        view.render(<Probe />);
        expect(answer()).toBe('phone');

        undo();
        view.render(<Probe key="again" />);
        expect(answer()).toBe('desktop');
    });
});

const ADMIN = join(process.cwd(), 'resources/js/admin');

function sourcesUnder(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return sourcesUnder(path);
        }

        return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
    });
}

describe('the token', () => {
    it('is declared once, in the stylesheet\'s static theme block, so the root carries it for the hook to read', () => {
        const css = readFileSync(join(ADMIN, 'app.css'), 'utf8');
        const declared = css.match(new RegExp(`${BREAKPOINT}\\s*:`, 'g')) ?? [];

        expect(declared).toHaveLength(1);
        expect(css).toMatch(new RegExp(`@theme static \\{[^}]*${BREAKPOINT}\\s*:`));
    });

    it('is the only width any admin source asks a media query about: no arbitrary breakpoint variant, no media query of its own', () => {
        // The hook asks the one question; the harness stands in for the answer in the tests.
        const exempt = [join('lib', 'viewport.ts'), join('components', 'ui', 'harness.tsx')];
        const offenders = sourcesUnder(ADMIN)
            .filter((path) => !exempt.some((name) => path.endsWith(name)))
            .filter((path) => /\b(?:max|min)-\[|matchMedia|@media/.test(readFileSync(path, 'utf8')))
            .map((path) => relative(ADMIN, path));

        expect(offenders).toEqual([]);
    });
});
