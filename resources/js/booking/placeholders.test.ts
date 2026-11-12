import { describe, expect, it } from 'vitest';
import { fill } from './placeholders';

/*
 * The translator's placeholders filled on the client: the one helper the signup's pages fill a
 * sentence of the strings prop with, and the booking app's words every line of booking.php, as
 * the server's translator fills the same sentence.
 */

describe('fill', () => {
    it('fills :name with the value, :Name with it capitalised and :NAME upper-cased, every occurrence', () => {
        expect(fill('We sent a link to :email, :Email, :EMAIL and :email again.', { email: 'owner@luna.example' })).toBe(
            'We sent a link to owner@luna.example, Owner@luna.example, OWNER@LUNA.EXAMPLE and owner@luna.example again.',
        );
    });

    it('fills several names in one sentence, the longer name first so :business never eats :businesses', () => {
        expect(fill('Set up :business at :address; :businesses is another name.', { business: 'Luna Studio', address: 'https://luna.example', businesses: 'many' })).toBe(
            'Set up Luna Studio at https://luna.example; many is another name.',
        );
    });

    it('leaves a placeholder no value names as written and ignores a value the sentence does not carry', () => {
        expect(fill('Setting up :business', { address: 'https://luna.example' })).toBe('Setting up :business');
        expect(fill('No placeholder here.', { business: 'Luna' })).toBe('No placeholder here.');
    });

    it('keeps the value as given, a dollar sign or an ampersand in it included', () => {
        expect(fill('Setting up :business', { business: 'Cut $ Colour & Co' })).toBe('Setting up Cut $ Colour & Co');
        expect(fill('Setting up :business', { business: '' })).toBe('Setting up ');
    });
});
