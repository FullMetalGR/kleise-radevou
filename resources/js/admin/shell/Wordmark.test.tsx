import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { ConsoleWordmark, CONSOLE_LABEL, Wordmark } from './Wordmark';

/*
 * The wordmark: the mark from its one source, the favicon under public/ that the site's layout
 * inlines too, beside the public name the server hands the page as the brand prop. The bundle
 * carries the mark and never the name.
 */

const MARK = readFileSync(resolve(process.cwd(), 'public/favicon.svg'), 'utf8').trim();

/** The mark's own markup as this document serializes it, to compare with what was drawn. */
function markAsSerialized(): string {
    const template = document.createElement('template');
    template.innerHTML = MARK;

    return template.content.firstElementChild?.outerHTML ?? '';
}

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => {
    mounted.unmount();
});

describe('the wordmark', () => {
    it('draws the mark from its one source, the favicon, beside the public name it is handed', () => {
        mounted.render(<Wordmark name="Example Bookings" />);

        const wordmark = mounted.host.querySelector('.brand-wordmark');

        expect(wordmark?.querySelector('.brand-wordmark__mark > svg.brand-mark')?.outerHTML).toBe(markAsSerialized());
        expect(wordmark?.querySelector('.brand-wordmark__name')?.textContent).toBe('Example Bookings');
        expect(wordmark?.textContent).toBe('Example Bookings');
    });

    it('hides the mark from assistive technology, the name being the wordmark\'s text', () => {
        mounted.render(<Wordmark name="Example Bookings" />);

        expect(mounted.host.querySelector('svg.brand-mark')?.getAttribute('aria-hidden')).toBe('true');
    });
});

describe('the console\'s wordmark', () => {
    it('names the console by the wordmark and the word console after it', () => {
        mounted.render(<ConsoleWordmark name="Example Bookings" />);

        expect(mounted.host.querySelector('.brand-wordmark .brand-wordmark__name')?.textContent).toBe('Example Bookings');
        expect(mounted.host.querySelector('.admin-console-label')?.textContent).toBe(CONSOLE_LABEL);
        expect(mounted.host.textContent).toBe('Example Bookings console');
    });

    it('puts the word console on the name\'s line where the two fit and lets it wrap under the name, beside the mark, where they do not, the name cut only where it alone is too wide, and keeps the whole of it in a title', () => {
        mounted.render(<ConsoleWordmark name="Example Bookings" />);

        const whole = mounted.host.querySelector('.admin-console-wordmark');
        const words = mounted.host.querySelector('.brand-wordmark > .brand-wordmark__mark + .brand-wordmark__words');

        expect(whole?.getAttribute('title')).toBe('Example Bookings console');
        expect(whole?.classList.contains('min-w-0')).toBe(true);
        expect(mounted.host.querySelector('.brand-wordmark')?.classList.contains('min-w-0')).toBe(true);
        expect(words?.classList.contains('flex-wrap')).toBe(true);
        expect(words?.classList.contains('min-w-0')).toBe(true);
        expect([...(words?.children ?? [])].map((part) => part.className.split(' ')[0])).toEqual(['brand-wordmark__name', 'admin-console-label']);
        expect(mounted.host.querySelector('.brand-wordmark__name')?.classList.contains('truncate')).toBe(true);

        mounted.render(<ConsoleWordmark name="Example Bookings" bare />);

        expect(mounted.host.querySelector('.brand-wordmark__words')?.classList.contains('contents')).toBe(true);
    });

    it('shows the mark alone where it is bare, the folded sidebar\'s head, its words kept for a screen reader', () => {
        mounted.render(<ConsoleWordmark name="Example Bookings" bare />);

        expect(mounted.host.querySelector('.brand-wordmark__name')?.classList.contains('sr-only')).toBe(true);
        expect(mounted.host.querySelector('.admin-console-label')?.classList.contains('sr-only')).toBe(true);
        expect(mounted.host.textContent).toBe('Example Bookings console');

        mounted.render(<ConsoleWordmark key="whole" name="Example Bookings" />);

        expect(mounted.host.querySelector('.brand-wordmark__name')?.classList.contains('sr-only')).toBe(false);
        expect(mounted.host.querySelector('.admin-console-label')?.classList.contains('sr-only')).toBe(false);
    });
});
