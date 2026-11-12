import { describe, expect, it } from 'vitest';
import { brandNameOf, pageTitle } from './title';

/*
 * The document's title: the page's own title, then the public name the server handed the first
 * page as the brand prop; the name alone where the page names nothing. No name is compiled in:
 * a page that carried none titles itself alone.
 */

describe('the title template', () => {
    it('follows the page\'s own title with the public name', () => {
        expect(pageTitle('Tenants', 'Example Bookings')).toBe('Tenants - Example Bookings');
        expect(pageTitle('Console sign in', 'bookings.example')).toBe('Console sign in - bookings.example');
    });

    it('is the public name alone where the page names nothing', () => {
        expect(pageTitle('', 'Example Bookings')).toBe('Example Bookings');
    });

    it('is the page\'s own title alone where no name was handed over', () => {
        expect(pageTitle('Sign in', '')).toBe('Sign in');
    });
});

describe('the public name of the first page', () => {
    it('reads the brand prop\'s name', () => {
        expect(brandNameOf({ errors: {}, brand: { name: 'Example Bookings' } })).toBe('Example Bookings');
    });

    it('reads nothing where the page carried no brand, as the sign-in page past its limit does', () => {
        expect(brandNameOf({ errors: {}, refusal: 'Too many attempts.' })).toBe('');
        expect(brandNameOf({ brand: null })).toBe('');
        expect(brandNameOf({ brand: { name: 7 } })).toBe('');
    });
});
