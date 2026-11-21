import { ArrowDown, ArrowUp } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/*
 * The figures kit Home and Reports share: the range tile, the kit's card holding its title, its
 * figure at one of three sizes, its comparison against the period before as a chip and its quiet
 * lines beneath, on the surface or on one of two fills; the ring gauge a rate may stand beside its
 * figure; and the header strip a card below the tiles opens on. Each screen words its own tiles
 * and says which way the change went and whether more is better; the kit only draws them.
 *
 * The chip is green for a rise, or a rise from nought, in a figure where more is better, and
 * neutral for every other pair. It is never red: a fall is not always bad news, and the words say
 * the change in full whatever the colour. Its arrow is decoration, hidden from assistive
 * technology.
 */

/** Which way a figure moved against the period before, as the chip draws it. */
export type ChangeKind = 'up' | 'down' | 'same' | 'from_zero';

type Size = 'hero' | 'large' | 'regular';

type Fill = 'strong' | 'attention';

/**
 * A figure's size; the hero one measured by its own tile, so a long amount shrinks to fit it, and
 * set at the foot of a tile taller than it needs, its chip beneath it. An amount of five figures
 * with its cents and its sign runs to about five and a half of its own size, so at 18 hundredths
 * of the tile it still keeps to one line.
 */
const SIZES: Record<Size, string> = {
    hero: 'text-[length:min(4.25rem,18cqi)] max-desktop:text-[length:min(3.375rem,18cqi)] mt-auto',
    large: 'text-[3.25rem] max-desktop:text-[2.625rem]',
    regular: 'text-[2.375rem]',
};

/** The tile's fill in place of the surface; the attention fill carries its own ink and rule. */
const FILLS: Record<Fill, string> = {
    strong: 'bg-tint-strong text-muted-foreground',
    attention: 'bg-attention-soft text-attention-ink inset-ring inset-ring-attention-line',
};

const VALUE = 'admin-figure-tile__value font-display leading-[1.05] font-semibold tracking-[-0.05em] tabular-nums wrap-anywhere';

const CHIP = 'admin-figure-tile__change mt-1 flex w-fit max-w-full items-center gap-1 rounded-event px-2.25 py-0.5 text-small font-bold';

type TileProps = {
    id: string;
    title: string;
    value?: ReactNode;
    change?: string | null;
    kind?: ChangeKind | null;
    moreIsBetter?: boolean;
    size?: Size;
    fill?: Fill;
    aside?: ReactNode;
    className?: string;
    children?: ReactNode;
};

/**
 * A range tile: the title, the figure where there is one with what stands beside it, the
 * comparison chip where there is one, then its lines, which take the tile's quiet ink.
 */
export function FigureTile({ id, title, value, change, kind = null, moreIsBetter = false, size = 'regular', fill, aside, className, children }: TileProps) {
    const figure = value !== undefined && <p className={[VALUE, fill === 'attention' ? '' : 'text-foreground', SIZES[size]].filter((part) => part !== '').join(' ')}>{value}</p>;

    return (
        <Card
            className={cn(
                `admin-figure-tile admin-figure-tile--${id} flex min-w-0 flex-col gap-1.5`,
                fill === undefined ? 'text-muted-foreground' : FILLS[fill],
                size === 'hero' && '@container',
                className,
            )}
            aria-labelledby={`figure-tile-${id}`}
        >
            <h2 id={`figure-tile-${id}`} className="admin-figure-tile__title text-body font-semibold">
                {title}
            </h2>
            {aside === undefined ? (
                figure
            ) : (
                <div className="admin-figure-tile__figure flex items-center justify-between gap-2.5">
                    {figure}
                    {aside}
                </div>
            )}
            {change !== undefined && change !== null && <Chip change={change} kind={kind} moreIsBetter={moreIsBetter} filled={fill !== undefined} />}
            {children}
        </Card>
    );
}

/** The way the chip's arrow points for each change; a rise from nought is a rise. */
const DIRECTIONS: Record<ChangeKind, 'up' | 'down' | null> = { up: 'up', from_zero: 'up', down: 'down', same: null };

/** The comparison chip: the words in full, an arrow beside a rise or a fall, the tone by whether more is better. */
function Chip({ change, kind, moreIsBetter, filled }: { change: string; kind: ChangeKind | null; moreIsBetter: boolean; filled: boolean }) {
    const direction = DIRECTIONS[kind ?? 'same'];
    const positive = moreIsBetter && direction === 'up';
    const tone = positive ? 'bg-positive-soft text-positive-ink' : `${filled ? 'bg-card' : 'bg-muted'} text-muted-foreground`;
    const Arrow = direction === 'up' ? ArrowUp : ArrowDown;

    return (
        <p className={`${CHIP} ${tone}`} data-tone={positive ? 'positive' : 'neutral'}>
            {direction !== null && <Arrow aria-hidden="true" data-direction={direction} className="size-3.5 shrink-0" strokeWidth={2.5} />}
            {change}
        </p>
    );
}

/** A quiet line under a tile's figure: a note, the hours behind a rate, another currency. */
export function FigureLine({ children }: { children: ReactNode }) {
    return <p className="admin-figure-tile__line text-small">{children}</p>;
}

/**
 * The ring gauge beside a rate's figure: a track and an arc the percent's share of it, a hundred
 * at most, starting at the top. At nought the track stands alone: an arc of no length would still
 * paint its round cap as a dot. Decoration alone: the tile says the rate.
 */
export function Gauge({ percent }: { percent: number }) {
    const shown = Math.min(100, percent);

    return (
        <svg className="admin-figure-tile__gauge size-20 shrink-0 -rotate-90 max-desktop:size-16" viewBox="0 0 80 80" aria-hidden="true">
            <circle cx="40" cy="40" r="34" fill="none" strokeWidth="9" className="stroke-muted" />
            {shown > 0 && <circle cx="40" cy="40" r="34" fill="none" strokeWidth="9" strokeLinecap="round" pathLength={100} strokeDasharray={`${shown} 100`} className="stroke-primary" />}
        </svg>
    );
}

/**
 * The header strip a card opens on: the title at the left, what the card carries beside it at the
 * right, above a rule; 45px on one line, a floor, since the house h2's 26px line and the room
 * around it come short of it. In a narrow card the title wraps, or the aside goes under it, and
 * the strip grows to hold them.
 */
export function Strip({ titleId, title, aside }: { titleId: string; title: string; aside?: ReactNode }) {
    return (
        <header className="admin-card-strip flex min-h-[45px] flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b px-4 py-2">
            <h2 id={titleId} className="font-semibold">
                {title}
            </h2>
            {aside}
        </header>
    );
}
