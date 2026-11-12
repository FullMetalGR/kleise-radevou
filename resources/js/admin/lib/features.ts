import type { ShellKey, ShellWords } from './strings';

/*
 * The words a plan feature is spelled in by the admin, keyed by slug, the one source for each:
 * the name the Billing screen lists it by, and the sentence saying it is gone from the plan, one
 * whole sentence holding the Billing screen's name as its :billing placeholder, which the caller
 * fills with that name as a link or as plain text, and a sentence for a free tenant, whose plan
 * is the console's and which has no Billing to add it on, naming platform support and linking
 * nothing. The narrowing banner on every signed-in page, the Staff screen without the feature and
 * the Billing screen's record beside the plan all read this table, so a second feature gets its
 * own words by adding its own row. Each word is a key of the shell's section, read through the
 * strings the caller hands in. A slug with no row here is one this build does not know and is
 * left out, so an arbitrary string never reaches the screen.
 */

type Words = { name: ShellKey; gone: ShellKey; goneFree: ShellKey };

const FEATURES: Record<string, Words> = {
    staff: {
        name: 'banners.features.staff.name',
        gone: 'banners.features.staff.gone',
        goneFree: 'banners.features.staff.gone_free',
    },
};

/** The placeholder the gone sentence holds the Billing screen's name in. */
const BILLING_SLOT = ':billing';

/** The Billing screen's name inside the gone sentence, a key of the shell's section. */
export const BILLING_NAME = 'banners.billing';

export type NamedFeature = { slug: string; name: string };

export function namedFeatures(slugs: readonly string[], shell: ShellWords): NamedFeature[] {
    return slugs.flatMap((slug) => {
        const words = FEATURES[slug];

        return words === undefined ? [] : [{ slug, name: shell.text(words.name) }];
    });
}

/**
 * The gone sentence for a slug, in two halves around its :billing placeholder, where the caller
 * places the Billing screen's name; null for a slug this build does not know.
 */
export function goneSentence(slug: string, shell: ShellWords): readonly [string, string] | null {
    const words = FEATURES[slug];

    if (words === undefined) {
        return null;
    }

    const sentence = shell.text(words.gone);
    const at = sentence.indexOf(BILLING_SLOT);

    return at === -1 ? [sentence, ''] : [sentence.slice(0, at), sentence.slice(at + BILLING_SLOT.length)];
}

/**
 * The gone sentence for a free tenant, whole, naming platform support; null for a slug this build
 * does not know.
 */
export function goneFreeSentence(slug: string, shell: ShellWords): string | null {
    const words = FEATURES[slug];

    return words === undefined ? null : shell.text(words.goneFree);
}
