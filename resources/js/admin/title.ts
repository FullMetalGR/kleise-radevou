/*
 * The document's title: the page's own title, then the public name. The name is the brand prop
 * the server hands every page, read from the first page the bundle boots on; it is never
 * compiled into the bundle, so a page that carried none, the sign-in page answered past its
 * limit before the shared props were, titles itself alone.
 */

export function pageTitle(title: string, name: string): string {
    if (name === '') {
        return title;
    }

    return title === '' ? name : `${title} - ${name}`;
}

/** The brand prop's public name on a page's props, or the empty string where the page carried none. */
export function brandNameOf(props: Record<string, unknown>): string {
    const brand = props.brand;

    if (typeof brand !== 'object' || brand === null || !('name' in brand)) {
        return '';
    }

    return typeof brand.name === 'string' ? brand.name : '';
}
