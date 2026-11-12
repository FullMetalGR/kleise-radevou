import MARK_FILE from '../../../../public/favicon.svg?raw';

/*
 * The wordmark: the brand's mark beside the public name, the same markup the site's header draws
 * from the same source. The mark is the favicon under public/, imported here as its text and
 * inlined as the site's layout inlines it, so the two can never draw two marks; it is this
 * repository's own file, never anything a request carried. The name is the brand prop the server
 * hands every page, never a name compiled into the bundle. The look is the brand's tokens
 * file's, shared with the site's stylesheet.
 */

/** The mark's markup, the file's text without its last line's end, as the site's layout inlines it. */
const MARK = MARK_FILE.trim();

/** What the console is called after the wordmark in its top bar and on its door. */
export const CONSOLE_LABEL = 'console';

export function Wordmark({ name, small = false }: { name: string; small?: boolean }) {
    return (
        <span className={small ? 'brand-wordmark brand-wordmark--small' : 'brand-wordmark'}>
            <span className="brand-wordmark__mark" dangerouslySetInnerHTML={{ __html: MARK }} />
            <span className="brand-wordmark__name">{name}</span>
        </span>
    );
}

/** The console's name: the wordmark, then the word console. */
export function ConsoleWordmark({ name }: { name: string }) {
    return (
        <span className="admin-console-wordmark inline-flex items-center gap-1.5">
            <Wordmark name={name} /> <span className="admin-console-label font-normal text-muted-foreground">{CONSOLE_LABEL}</span>
        </span>
    );
}
