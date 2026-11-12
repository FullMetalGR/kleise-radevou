import { describe, expect, it } from 'vitest';
import { tagged } from './tagged';

/*
 * A sentence's named tag pairs turned into parts: the signup's terms box, whose one sentence
 * of signup.php carries its two link texts between <terms> and <privacy>. The sentences here
 * are neutral tokens, never a line of a language file.
 */

const part = (name: string) => (text: string) => ({ name, text });

describe('tagged', () => {
    it('hands each named pair\'s text to its part and keeps the text around it as it is, in order', () => {
        expect(tagged('lead <one>first</one> middle <two>second</two> tail', { one: part('one'), two: part('two') })).toEqual([
            'lead ',
            { name: 'one', text: 'first' },
            ' middle ',
            { name: 'two', text: 'second' },
            ' tail',
        ]);
    });

    it('walks the pairs in the sentence\'s order, whatever order the parts are named in', () => {
        expect(tagged('<two>b</two><one>a</one>', { one: part('one'), two: part('two') })).toEqual([
            { name: 'two', text: 'b' },
            { name: 'one', text: 'a' },
        ]);
    });

    it('leaves a pair no part names, and a tag with no partner, as plain text', () => {
        expect(tagged('a <other>b</other> c', { one: part('one') })).toEqual(['a <other>b</other> c']);
        expect(tagged('a <one>b c', { one: part('one') })).toEqual(['a <one>b c']);
    });

    it('reads nothing in the text as markup: an entity and a quote stay characters, and a pair holding another tag is no pair', () => {
        expect(tagged('x <one>a & <b> "c"</one> y', { one: part('one') })).toEqual(['x <one>a & <b> "c"</one> y']);
        expect(tagged('x <one>a &amp; "c"</one> y', { one: part('one') })).toEqual(['x ', { name: 'one', text: 'a &amp; "c"' }, ' y']);
    });

    it('leaves the tags of a pair holding another named pair as text, and turns the inner pair into its part', () => {
        expect(tagged('<one>a <two>b</two></one>', { one: part('one'), two: part('two') })).toEqual([
            '<one>a ',
            { name: 'two', text: 'b' },
            '</one>',
        ]);
    });

    it('answers a sentence with no pair as the one string', () => {
        expect(tagged('no-tags-token', { one: part('one') })).toEqual(['no-tags-token']);
        expect(tagged('', { one: part('one') })).toEqual([]);
    });
});
