/*
 * A sentence's named tag pairs turned into parts, so a sentence is never cut into fragments: a
 * sentence of a language file keeps a text that becomes something else, a link, between a named
 * opening and closing tag, `<terms>` and `</terms>`, and this helper hands each pair's text to
 * the part its name names, keeping the text around the pairs as plain strings in order. Nothing
 * is read as HTML: the parts are the caller's, the strings stay characters, and a pair no part
 * names or a tag with no partner stays text as written. A pair holding another tag is no pair:
 * its own two tags and the text around the inner tag stay text as written, and an inner pair a
 * part names still becomes its part.
 *
 * It sits beside fill() and reaches nothing else, so the booking app, which never imports it,
 * carries none of it; the signup form of the admin bundle is its one reader.
 */

const PAIR = /<([a-z]+)>([^<]*)<\/\1>/g;

export function tagged<T>(sentence: string, parts: Readonly<Record<string, (text: string) => T>>): (string | T)[] {
    const out: (string | T)[] = [];
    let plain = '';
    let from = 0;

    for (const match of sentence.matchAll(PAIR)) {
        const [whole, name = '', text = ''] = match;
        const part = Object.prototype.hasOwnProperty.call(parts, name) ? parts[name] : undefined;

        plain += sentence.slice(from, match.index);
        from = match.index + whole.length;

        if (part === undefined) {
            plain += whole;
            continue;
        }

        if (plain !== '') {
            out.push(plain);
        }

        out.push(part(text));
        plain = '';
    }

    plain += sentence.slice(from);

    if (plain !== '') {
        out.push(plain);
    }

    return out;
}
