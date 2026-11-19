import { cn } from '@/lib/utils';
import MARK from './mark.svg';

/*
 * The platform's mark in a business's admin, beside the business's own name in the top bar: the
 * artwork from its one file, drawn through an image so it keeps its own blue whatever theme the
 * business picked, then the site's name in the display face, a domain ending such as ".gr" set
 * lighter in the tertiary ink. The artwork is decoration, its alt empty, as the name beside it
 * says what it is. Bare, the artwork stands alone and the name stays for a screen reader.
 */

/** A name split at its domain ending, ['kleiseradevou', '.gr'], or whole beside an empty ending. */
export function splitEnding(name: string): [string, string] {
    const match = /^(.+?)(\.[a-z]{2,})$/i.exec(name);

    return match ? [match[1] ?? name, match[2] ?? ''] : [name, ''];
}

export function Mark({ name, bare = false }: { name: string; bare?: boolean }) {
    const [stem, ending] = splitEnding(name);

    return (
        <span className="admin-mark inline-flex shrink-0 items-center gap-2.5">
            <img src={MARK} alt="" width={40} height={40} className="admin-mark__image size-10 shrink-0" />
            <span className={cn('admin-mark__name font-display text-[17px] leading-none font-bold tracking-[-0.03em] text-foreground', bare && 'sr-only')}>
                {stem}
                {ending !== '' && <span className="admin-mark__ending font-medium text-ink-tertiary">{ending}</span>}
            </span>
        </span>
    );
}
