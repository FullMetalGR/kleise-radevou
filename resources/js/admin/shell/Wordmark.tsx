import MARK_FILE from '../../../../public/favicon.svg?raw';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

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

/** What the console is called after the wordmark at its sidebar's head and on its door. */
export const CONSOLE_LABEL = 'console';

/*
 * The wordmark; bare, the mark alone shows and the name is kept for a screen reader. In a place
 * narrower than its words the name gives way and is cut with an ellipsis, on a line as tall as
 * the mark so the cut takes no letter's tail. What is handed to follow the name shares its line
 * where the two fit and wraps to a line of its own under the name, beside the mark, where they do
 * not, so the name is cut only where it alone is wider than its line.
 */
export function Wordmark({ name, bare = false, children }: { name: string; bare?: boolean; children?: ReactNode }) {
    const words = <span className={cn('brand-wordmark__name min-w-0 truncate leading-normal', bare && 'sr-only')}>{name}</span>;

    return (
        <span className="brand-wordmark min-w-0">
            <span className="brand-wordmark__mark" dangerouslySetInnerHTML={{ __html: MARK }} />
            {children === undefined ? (
                words
            ) : (
                <span className={cn('brand-wordmark__words', bare ? 'contents' : 'flex min-w-0 flex-wrap items-center gap-x-1.5')}>
                    {words} {children}
                </span>
            )}
        </span>
    );
}

/*
 * The console's name: the wordmark, then the word console; bare, the mark alone, as the folded
 * sidebar shows it. Where the two do not fit on one line, as at the sidebar's head, the word
 * console goes under the name, and the whole of it is the title a pointer reads, as the
 * business's chip keeps its name. The word sits inside the wordmark and keeps the admin's own
 * face, weight and spacing, not the brand's.
 */
export function ConsoleWordmark({ name, bare = false }: { name: string; bare?: boolean }) {
    return (
        <span className="admin-console-wordmark inline-flex max-w-full min-w-0" title={`${name} ${CONSOLE_LABEL}`}>
            <Wordmark name={name} bare={bare}>
                <span className={cn('admin-console-label font-text leading-normal font-normal tracking-normal text-muted-foreground', bare && 'sr-only')}>{CONSOLE_LABEL}</span>
            </Wordmark>
        </span>
    );
}
