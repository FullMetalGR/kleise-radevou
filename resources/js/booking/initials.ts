/*
 * The letters a disc shows for a name where no picture is: the first letter of the first word
 * and, where asked for two, of the last, upper-cased. One helper for the summary card's head,
 * which shows the business's one initial, and the staff cards, which show a person's two, so
 * the two surfaces cannot drift apart in how they read a name.
 */

export function initialsOf(name: string, letters: 1 | 2 = 2): string {
    const words = name.trim().split(/\s+/);
    const first = Array.from(words[0] ?? '')[0] ?? '';
    const last = letters === 2 && words.length > 1 ? (Array.from(words[words.length - 1] ?? '')[0] ?? '') : '';

    return (first + last).toUpperCase();
}
