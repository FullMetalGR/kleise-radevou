/*
 * The translator's placeholders filled on the client: a sentence keeps the :name placeholders of
 * its language file, and this one helper fills them as the server's translator would, :name with
 * the value, :Name with it capitalised and :NAME upper-cased, the longer names first so :business
 * never eats :businesses. A name the sentence does not carry changes nothing, and a placeholder no
 * value names stays as written. The value is put in as given: nothing in it is read as a pattern.
 *
 * One copy for both bundles: it sits under the booking app, which imports nothing from outside its
 * own directory, and the admin imports it from here as it imports the booking app's time and
 * money. Every occurrence is replaced by splitting on it and joining with the value, which reads
 * the same as replaceAll and runs on the embed's ES2020 target.
 */

function capitalised(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function replaced(text: string, placeholder: string, value: string): string {
    return text.split(placeholder).join(value);
}

export function fill(sentence: string, values: Readonly<Record<string, string>>): string {
    return Object.entries(values)
        .sort(([a], [b]) => b.length - a.length)
        .reduce((filled, [name, value]) => replaced(replaced(replaced(filled, `:${name.toUpperCase()}`, value.toUpperCase()), `:${capitalised(name)}`, capitalised(value)), `:${name}`, value), sentence);
}
