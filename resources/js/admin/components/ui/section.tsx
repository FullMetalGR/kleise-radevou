import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { Card } from './card';

/*
 * A form section: a form card named by its heading, a row holding the heading and an optional
 * note beside it, then the fields. The card is a size container and the fields' grid asks it,
 * not the window, how wide it is: two columns where the card is at least 36rem wide, one where it
 * is narrower, so a form keeps its shape wherever the page puts it. A container answers with the
 * width inside its own padding, so the card holds none and the width asked for is the card's from
 * edge to edge, in the compact layout as in the full one: the heading row and the fields hold the
 * form card's room between them. A wide section keeps its fields on one column at every width. A
 * danger section is marked by a red outline and a red heading, never by the colour of its words
 * alone. The place keeps its own hook class in className.
 */

/** The form card's room above and at the sides, held by the heading row. */
const HEAD_ROOM = 'px-6.5 pt-6 max-wide:px-5 max-wide:pt-5';

/** The form card's room at the sides and the foot, held by the fields. */
const BODY_ROOM = 'px-6.5 pb-6.5 max-wide:px-5 max-wide:pb-5';

type Props = {
    id: string;
    title: string;
    meta?: string;
    wide?: boolean;
    danger?: boolean;
    className?: string;
    children: ReactNode;
};

export function Section({ id, title, meta, wide = false, danger = false, className, children }: Props) {
    return (
        <Card
            variant="form"
            flush
            className={cn(`admin-section admin-section--${id} @container flex min-w-0 flex-col gap-4`, danger && 'ring-[1.5px] ring-destructive/40', className)}
            aria-labelledby={`section-${id}`}
        >
            <header className={cn('flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1', HEAD_ROOM)}>
                <h2 id={`section-${id}`} className={cn('text-card-title', danger && 'text-destructive')}>
                    {title}
                </h2>
                {meta !== undefined && <p className="admin-section__meta text-sm text-muted-foreground">{meta}</p>}
            </header>
            <div className={cn('grid gap-x-6 gap-y-4', BODY_ROOM, !wide && '@xl:grid-cols-2')}>{children}</div>
        </Card>
    );
}
