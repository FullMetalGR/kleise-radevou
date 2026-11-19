import type { ReactElement } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Caret, ChipAlert, ChipCard, ChipClock, Chevron, ChipCross, ChipHourglass, ChipTick, ChipTimer, Cross, Info, NoteSheet, Plus, Success, Tick, Warning } from './glyphs';
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
            [<Cross key="cross" />, 'lucide-x'],
            [<Info key="info" />, 'lucide-info'],
            [<Warning key="warning" />, 'lucide-triangle-alert'],
            [<Success key="success" />, 'lucide-circle-check'],
            [<ChipClock key="chip-clock" />, 'lucide-clock'],
            [<ChipTick key="chip-tick" />, 'lucide-check'],
            [<ChipCard key="chip-card" />, 'lucide-credit-card'],
            [<ChipHourglass key="chip-hourglass" />, 'lucide-hourglass'],
            [<ChipCross key="chip-cross" />, 'lucide-x'],
            [<ChipAlert key="chip-alert" />, 'lucide-circle-alert'],
            [<ChipTimer key="chip-timer" />, 'lucide-timer'],
        ];

        for (const [glyph, name] of drawn) {
            view.render(glyph);

            const svg = view.host.querySelector('svg');

            expect(svg?.classList.contains('lucide')).toBe(true);
            expect(svg?.classList.contains(name)).toBe(true);
            expect(svg?.getAttribute('aria-hidden')).toBe('true');
        }
    });

    it('draws a status chip\'s marks at 13px, to sit beside the chip\'s 13px label, and a notice\'s at 16px', () => {
        const sized = (glyph: ReactElement) => {
            view.render(glyph);

            return [...(view.host.querySelector('svg')?.classList ?? [])].filter((name) => name.startsWith('size-'));
        };

        for (const glyph of [<ChipClock key="clock" />, <ChipTick key="tick" />, <ChipCard key="card" />, <ChipHourglass key="hourglass" />, <ChipCross key="cross" />, <ChipAlert key="alert" />, <ChipTimer key="timer" />]) {
            expect(sized(glyph)).toEqual(['size-3.25']);
        }

        for (const glyph of [<Info key="info" />, <Warning key="warning" />, <Success key="success" />, <Cross key="cross" />]) {
            expect(sized(glyph)).toEqual(['size-4']);
        }
    });

    it('holds a notice\'s glyphs at their size in a row short of room, the cross of its close control with them', () => {
        const held = (glyph: ReactElement) => {
            view.render(glyph);

            return view.host.querySelector('svg')?.classList.contains('shrink-0');
        };

        expect([<Info key="info" />, <Warning key="warning" />, <Success key="success" />, <Cross key="cross" />].map(held)).toEqual([true, true, true, true]);
    });
});
