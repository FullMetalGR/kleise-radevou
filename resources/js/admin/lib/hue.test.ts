import { describe, expect, it } from 'vitest';
import { colourHue, hueOf, memberHue, PALETTE, STAFF_COLOURS } from './hue';

describe('a member\'s hue', () => {
    it('names the palette\'s seven hues by the seven words, in the palette\'s order', () => {
        expect(STAFF_COLOURS.map(colourHue)).toEqual(PALETTE);
        expect(colourHue('purple')).toBe('#cc79a7');
        expect(colourHue('sky_blue')).toBe('#56b4e9');
    });

    it('draws a member in their own colour where they picked one, else in the spread over their id', () => {
        expect(memberHue({ id: 2, colour: null })).toBe(PALETTE[2]);
        expect(memberHue({ id: 2, colour: 'purple' })).toBe('#cc79a7');
        expect(memberHue({ id: 9, colour: null })).toBe(PALETTE[2]);
    });

    it('reads a member\'s own colour by id from the members who picked one, the others on the spread', () => {
        const own = [{ id: 1, colour: 'purple' as const }];

        expect(hueOf(1, own)).toBe('#cc79a7');
        expect(hueOf(2, own)).toBe(PALETTE[2]);
        expect(hueOf(1, [])).toBe(PALETTE[1]);
    });
});
