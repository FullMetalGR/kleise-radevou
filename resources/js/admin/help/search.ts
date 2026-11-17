import type { HelpAnswer, HelpTopic } from './types';

/*
 * The panel's search over the answers, a pure function of the topics and what was typed. Both
 * sides are folded the same way before they are compared: lower case, every accent dropped (the
 * Greek tonos and dialytika as much as a Latin acute), and the Greek final sigma read as a sigma,
 * so a word typed without its accents, in capitals or ending mid-word still finds itself. What
 * was typed is cut into words at anything that is not a letter or a figure, and an answer matches
 * when every word is found somewhere in its question or its paragraphs, in any order. Typing no
 * word at all is no search: the panel shows the topics.
 */

/** One answer a search found, with the topic it stands under. */
export type Found = { topic: string; answer: HelpAnswer };

const FINAL_SIGMA = /\u03c2/g;

/**
 * The sigma for a final sigma, the letter after it among the code points. Worked out as the text
 * is read rather than written as a letter, so the bundle, which carries ASCII alone, holds no
 * Greek a minifier would write out.
 */
function sigmaOf(final: string): string {
    return String.fromCharCode(final.charCodeAt(0) + 1);
}

/** A text as the search compares it: lower case, no accents, the final sigma a sigma. */
function folded(text: string): string {
    return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(FINAL_SIGMA, sigmaOf);
}

/** The words typed, folded, in the order typed; none for a query of spaces and punctuation alone. */
function wordsOf(query: string): string[] {
    return folded(query)
        .split(/[^\p{L}\p{N}]+/u)
        .filter((word) => word !== '');
}

/**
 * The answers every typed word is found in, in the order the topics list them; null where the
 * query holds no word, which is no search.
 */
export function matchesOf(topics: readonly HelpTopic[], query: string): Found[] | null {
    const words = wordsOf(query);

    if (words.length === 0) {
        return null;
    }

    return topics.flatMap((topic) =>
        topic.answers
            .filter((answer) => {
                const text = folded([answer.question, ...answer.answer].join('\n'));

                return words.every((word) => text.includes(word));
            })
            .map((answer) => ({ topic: topic.key, answer })),
    );
}
