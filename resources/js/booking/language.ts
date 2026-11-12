/*
 * The booking app's two languages: English and Greek, each by its code, and nothing else. The app
 * holds one at a time, the config's language once the config is in hand, and passes it on every
 * API call as lang, so the server answers in it and the hold records it; a page path, which takes
 * no lang, it asks under the language's prefix instead.
 *
 * The embed's start is the host page's <html lang> where it names one of the two, a region subtag
 * ignored, so el-GR starts Greek; anything else names none and the start is the tenant default the
 * config names. The config is asked with the start, and the server answers the language the app
 * then holds.
 */

export type Language = 'en' | 'el';

/** What the API's language parameter is called in a query string, the server's Languages::QUERY. */
export const LANGUAGE_QUERY = 'lang';

/**
 * A language code off the wire, or null for anything that is not exactly one of the two.
 */
export function parseLanguage(value: unknown): Language | null {
    return value === 'en' || value === 'el' ? value : null;
}

/**
 * The language a language tag names, its primary subtag read case-insensitively, a region or any
 * later subtag ignored; null for a tag naming neither language, an empty one or none.
 */
export function languageOfTag(tag: string | null): Language | null {
    const primary = (tag ?? '').trim().split('-')[0] ?? '';

    return parseLanguage(primary.toLowerCase());
}

/**
 * The host page's language, the embed's start: its root element's lang, or null where it names
 * neither language.
 */
export function hostLanguageOf(doc: Document): Language | null {
    return languageOfTag(doc.documentElement.getAttribute('lang'));
}

/**
 * The path with the language appended as lang, beside any query it already carries; the path as
 * it is where no language is given.
 */
export function withLanguage(path: string, language: Language | null | undefined): string {
    if (language === null || language === undefined) {
        return path;
    }

    return `${path}${path.includes('?') ? '&' : '?'}${LANGUAGE_QUERY}=${language}`;
}

/**
 * The other language: the one the switch leads to.
 */
export function otherOf(language: Language): Language {
    return language === 'en' ? 'el' : 'en';
}

/**
 * The locale the formatters take for a language on this machine: the names of days and months and
 * the glyphs are the language's, never the machine's, while the order and the hour cycle stay
 * what the machine gives, since only the names change. A machine speaking the language keeps its
 * own locale whole, so an English page on an en-GB machine reads day first on the 24-hour clock
 * as it did; for another the locale is the language with the machine's region, where it has one,
 * and the machine's own hour cycle, so a Greek page on an en-GB machine keeps the 24-hour clock
 * that bare el would turn into twelve. The machine is the formatter's default locale unless one
 * is given.
 */
export function localeOf(language: Language, machine: string = new Intl.DateTimeFormat().resolvedOptions().locale): string {
    if (languageOfTag(machine) === language) {
        return machine;
    }

    const { region } = new Intl.Locale(machine);
    const { hourCycle } = new Intl.DateTimeFormat(machine, { hour: 'numeric' }).resolvedOptions();

    return new Intl.Locale(language, { region, hourCycle }).toString();
}

/**
 * A page's path under a language's prefix, the same ASCII segments in both, the root as the bare
 * prefix, and the path as it is for no prefix: TenantPages::uri on the server.
 */
export function pathUnder(path: string, language: Language | null): string {
    return language === null ? path : `/${language}${path === '/' ? '' : path}`;
}

/**
 * The language a page's path names by its prefix, its first segment, or null for a bare path:
 * TenantPages::prefixOf on the server. A page's forms and links keep it, so a customer reading
 * under one prefix stays under it.
 */
export function prefixOf(pathname: string): Language | null {
    return parseLanguage(pathname.split('/')[1]);
}

/**
 * Where the hosted page's language switch leads: the same path under the other language's prefix,
 * the default's explicit prefix included, the query kept as it came, a token in it or in the path
 * alike. The path is read as the server reads it, its slashes trimmed, and a prefix it carries is
 * taken off first, so the switch of /el/manage/x leads to /en/manage/x and that of /manage/x, in
 * the default, to the other's. TenantPages::addresses computes the same address, held to it over
 * one fixture.
 */
export function switchHrefOf(pathname: string, search: string, language: Language): string {
    const trimmed = pathname.replace(/^\/+|\/+$/g, '');
    const [first = '', ...rest] = trimmed.split('/');
    const bare = parseLanguage(first) === null ? trimmed : rest.join('/');

    return pathUnder(`/${bare}`, otherOf(language)) + search;
}
