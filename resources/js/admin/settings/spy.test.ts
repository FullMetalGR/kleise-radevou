import { describe, expect, it } from 'vitest';
import { currentSection } from './spy';

/*
 * Which section the page is reading: the last one whose top has scrolled up to the line or past
 * it, the first while none has, and none for a page with no sections.
 */

const sections = (tops: number[]) => tops.map((top, at) => ({ id: ['general', 'booking-page', 'booking-rules'][at] ?? `section-${at}`, top }));

describe('the current section', () => {
    it('is the first while no section has reached the line', () => {
        expect(currentSection(sections([300, 900, 1500]), 140)).toBe('general');
    });

    it('is the last section whose top is at the line or above it', () => {
        expect(currentSection(sections([-200, 100, 700]), 140)).toBe('booking-page');
        expect(currentSection(sections([-900, -300, 139]), 140)).toBe('booking-rules');
        expect(currentSection(sections([-900, 140, 141]), 140)).toBe('booking-page');
    });

    it('is none for a page with no sections', () => {
        expect(currentSection([], 140)).toBeNull();
    });
});
