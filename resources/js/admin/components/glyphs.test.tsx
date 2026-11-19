import type { ReactElement } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Caret, Chevron, NoteSheet, Plus, Tick } from './glyphs';
import { mount, type Mounted } from './ui/harness';

/*
 * The admin's small glyphs come from the one icon set every screen draws from, each by the
 * set's own name, so a caret here and an icon on another screen share their stroke and their
 * corners. Each is decoration and hidden from assistive technology.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('the glyphs', () => {
    it('draws each glyph with the icon set, hidden from assistive technology', () => {
        const drawn: [ReactElement, string][] = [
            [<Caret key="caret" />, 'lucide-chevron-down'],
            [<Chevron key="previous" to="previous" />, 'lucide-chevron-left'],
            [<Chevron key="next" to="next" />, 'lucide-chevron-right'],
            [<Tick key="tick" />, 'lucide-check'],
            [<NoteSheet key="note" />, 'lucide-sticky-note'],
            [<Plus key="plus" />, 'lucide-plus'],
        ];

        for (const [glyph, name] of drawn) {
            view.render(glyph);

            const svg = view.host.querySelector('svg');

            expect(svg?.classList.contains('lucide')).toBe(true);
            expect(svg?.classList.contains(name)).toBe(true);
            expect(svg?.getAttribute('aria-hidden')).toBe('true');
        }
    });
});
