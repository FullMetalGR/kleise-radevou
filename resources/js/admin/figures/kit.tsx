import type { ReactNode } from 'react';

/*
 * The figures kit Home and Reports share: the range tile, the kit's card holding its title,
 * its figure, its comparison against the period before and its quiet lines beneath, and the
 * header strip a card below the tiles opens on. Each screen words its own tiles; the kit only
 * draws them.
 */

const LINE = 'text-xs leading-4 text-muted-foreground';

/** A range tile: the title, the figure where there is one, the comparison where there is one, then its lines. */
export function FigureTile({ id, title, value, change, children }: { id: string; title: string; value?: ReactNode; change?: string | null; children?: ReactNode }) {
    return (
        <section className={`admin-figure-tile admin-figure-tile--${id} flex flex-col gap-1 rounded-xl border bg-card p-4`} aria-labelledby={`figure-tile-${id}`}>
            <h2 id={`figure-tile-${id}`} className="admin-figure-tile__title text-sm font-medium text-muted-foreground">
                {title}
            </h2>
            {value !== undefined && <p className="admin-figure-tile__value text-2xl leading-8 font-semibold">{value}</p>}
            {change !== undefined && change !== null && <p className={`admin-figure-tile__change ${LINE}`}>{change}</p>}
            {children}
        </section>
    );
}

/** A quiet line under a tile's figure: a note, the hours behind a rate, another currency. */
export function FigureLine({ children }: { children: ReactNode }) {
    return <p className={`admin-figure-tile__line ${LINE}`}>{children}</p>;
}

/**
 * The header strip a card opens on: the title at the left, what the card carries beside it at the
 * right, above a rule; 45px (12px above and below a 20px line and the rule), pinned, since the
 * house h2 sits on a 26px line.
 */
export function Strip({ titleId, title, aside }: { titleId: string; title: string; aside?: ReactNode }) {
    return (
        <header className="admin-card-strip flex h-[45px] items-center justify-between gap-3 border-b px-4">
            <h2 id={titleId} className="font-semibold">
                {title}
            </h2>
            {aside}
        </header>
    );
}
