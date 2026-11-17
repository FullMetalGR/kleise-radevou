import { describe, expect, it } from 'vitest';
import { matchesOf } from './search';
import type { HelpTopic } from './types';

/*
 * The panel's search over made-up answers: no word typed is no search; case folded; every typed
 * word found somewhere in the question or the paragraphs, in any order; Latin and Greek accents
 * dropped, the Greek tonos and dialytika included; the Greek final sigma read as a sigma, typed
 * or stored; and the matches in the order the topics list them, each with its topic. The Greek
 * is written as escapes.
 */

/** "Change the time of a booking?" with the booking in the genitive, which ends in a final sigma. */
const GREEK_QUESTION = '\u0391\u03bb\u03bb\u03b1\u03b3\u03ae \u03ce\u03c1\u03b1\u03c2 \u03ba\u03c1\u03ac\u03c4\u03b7\u03c3\u03b7\u03c2;';

/** "A product in the morning": a dialytika on its own, and a dialytika under a tonos. */
const GREEK_ANSWER = '\u0388\u03bd\u03b1 \u03c0\u03c1\u03bf\u03ca\u03cc\u03bd \u03c4\u03bf \u03c0\u03c1\u03c9\u0390.';

const TOPICS: HelpTopic[] = [
    {
        key: 'calendar',
        title: 'The calendar',
        answers: [
            { key: 'move', question: 'How do I move a booking?', answer: ['Drag it to its new time on the Calendar.', 'The customer gets an email.'] },
            { key: 'cafe', question: 'Can the caf\u00e9 close early?', answer: ['Add a closure for the evening.'] },
        ],
    },
    {
        key: 'greek',
        title: 'In Greek',
        answers: [{ key: 'time', question: GREEK_QUESTION, answer: [GREEK_ANSWER] }],
    },
    {
        key: 'team',
        title: 'The team',
        answers: [{ key: 'invite', question: 'How do I invite a colleague?', answer: ['Send an invitation from the Team screen.'] }],
    },
];

/** The keys of what a search found, topic and answer, in order. */
function found(query: string): string[] | null {
    return matchesOf(TOPICS, query)?.map((match) => `${match.topic}.${match.answer.key}`) ?? null;
}

describe('the help search', () => {
    it('is no search where nothing but spaces and punctuation is typed', () => {
        expect(found('')).toBeNull();
        expect(found('   ')).toBeNull();
        expect(found(' ?! - ')).toBeNull();
    });

    it('folds case on both sides', () => {
        expect(found('MOVE')).toEqual(['calendar.move']);
        expect(found('calendar')).toEqual(['calendar.move']);
    });

    it('finds an answer only where every typed word is in its question or its paragraphs, in any order', () => {
        expect(found('booking email')).toEqual(['calendar.move']);
        expect(found('email booking')).toEqual(['calendar.move']);
        expect(found('booking refund')).toEqual([]);
    });

    it('finds part of a word, and lists the matches in the topics\' order with their topic', () => {
        expect(found('invit')).toEqual(['team.invite']);
        expect(found('how do')).toEqual(['calendar.move', 'team.invite']);
        expect(matchesOf(TOPICS, 'invite')?.[0]?.answer.question).toBe('How do I invite a colleague?');
    });

    it('drops a Latin accent on either side', () => {
        expect(found('cafe')).toEqual(['calendar.cafe']);
        expect(found('CAF\u00c9')).toEqual(['calendar.cafe']);
        expect(found('cl\u00f3se')).toEqual(['calendar.cafe']);
    });

    it('drops the Greek tonos, typed in capitals or without it', () => {
        // KRATHSHS in capitals, no tonos, and ora without its tonos.
        expect(found('\u039a\u03a1\u0391\u03a4\u0397\u03a3\u0397\u03a3')).toEqual(['greek.time']);
        expect(found('\u03c9\u03c1\u03b1')).toEqual(['greek.time']);
        // allagi with its tonos on the wrong letter still folds to the same word.
        expect(found('\u03ac\u03bb\u03bb\u03b1\u03b3\u03b7')).toEqual(['greek.time']);
    });

    it('drops the dialytika, alone and under a tonos', () => {
        expect(found('\u03c0\u03c1\u03bf\u03b9\u03bf\u03bd')).toEqual(['greek.time']);
        expect(found('\u03c0\u03c1\u03c9\u03b9')).toEqual(['greek.time']);
        expect(found('\u03a0\u03a1\u039f\u03aa\u039f\u039d')).toEqual(['greek.time']);
    });

    it('reads the final sigma as a sigma, typed at the end of part of a word or stored at the end of one', () => {
        // krates, typed with a final sigma, is the start of kratesis.
        expect(found('\u03ba\u03c1\u03ac\u03c4\u03b7\u03c2')).toEqual(['greek.time']);
        // kratisis written with a plain sigma at its end finds the stored final sigma.
        expect(found('\u03ba\u03c1\u03b1\u03c4\u03b7\u03c3\u03b7\u03c3')).toEqual(['greek.time']);
        expect(found('\u03ce\u03c1\u03b1\u03c3 \u03ba\u03c1\u03ac\u03c4\u03b7\u03c3\u03b7\u03c2')).toEqual(['greek.time']);
    });
});
