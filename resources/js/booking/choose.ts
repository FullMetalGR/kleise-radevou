/*
 * The translator's choice syntax, mirrored once for the booking bundle and the admin alike. It
 * imports no language table, so the admin reads it without carrying the bundle's sentences, and
 * both are held to the fixture the server's own selector answers (tests/Support/choices.json).
 */

/** A segment's leading condition and the text after it: {n}, [a,b], [a,*] or [*,b]. */
const CONDITION = /^[{[]([-?\d|*,.]*)[}\]](.*)/s;

/**
 * A line chosen by a count, the translator's MessageSelector::choose: the first segment whose
 * condition the count meets, trimmed; else, the conditions stripped, the first segment for one
 * and the second for any other count, the plural rule English and Greek share, or the first where
 * there is no second.
 */
export function choose(line: string, count: number): string {
    const segments = line.split('|');

    for (const segment of segments) {
        const chosen = chosenBy(segment, count);

        if (chosen !== null) {
            return chosen.trim();
        }
    }

    const plain = segments.map((segment) => segment.replace(CONDITION, '$2'));

    return plain[count === 1 ? 0 : 1] ?? plain[0] ?? line;
}

/**
 * A segment's text where its condition holds for the count, else null.
 */
function chosenBy(segment: string, count: number): string | null {
    const match = CONDITION.exec(segment);

    return match !== null && holds(match[1] ?? '', count) ? (match[2] ?? '') : null;
}

/**
 * Whether a condition holds for a count: a range of two bounds, either open with a star, or one
 * number.
 */
function holds(condition: string, count: number): boolean {
    const comma = condition.indexOf(',');

    if (comma === -1) {
        return condition !== '' && Number(condition) === count;
    }

    const from = condition.slice(0, comma);
    const to = condition.slice(comma + 1);

    return (to === '*' && count >= Number(from)) || (from === '*' && count <= Number(to)) || (count >= Number(from) && count <= Number(to));
}
