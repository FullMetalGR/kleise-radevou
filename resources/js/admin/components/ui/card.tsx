import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/*
 * The admin's panel, in three kinds. A tile holds content: the surface at the large radius under
 * the card shadow, which draws its own hairline edge, so no border. A form card is the same
 * surface with a little less room above its title row than at its sides and its foot. An inner
 * card groups something inside another card: the smaller radius inside a line ring, with no
 * shadow, so it reads as part of the card around it. Below the wide breakpoint, the compact
 * layout, the two outer kinds take a smaller radius and less room.
 *
 * A flush card has no padding, for a table that runs to its edges, and clips what it holds to its
 * rounded corner, so a filled head row stays inside it. The clip makes no scroll box, so a sticky
 * part inside still sticks to the page. Nothing in a flush card draws past its edge: menus and
 * popovers open in a portal, and a focus ring sits inside the room around the control.
 *
 * The card is a section, so a card its heading names is a region, as every panel it replaces
 * was. It adds no hook class: each place keeps its own in className, beside its layout classes,
 * and a class passed there wins over the card's own.
 */

type Variant = 'tile' | 'form' | 'inner';

const SURFACE = 'bg-card rounded-tile shadow-card max-wide:rounded-tile-compact';

const KINDS: Record<Variant, { readonly look: string; readonly room: string }> = {
    tile: { look: SURFACE, room: 'p-6.5 max-wide:p-5' },
    form: { look: SURFACE, room: 'px-6.5 pt-6 pb-6.5 max-wide:p-5' },
    inner: { look: 'bg-card rounded-inner ring-[1.5px] ring-border', room: 'p-4' },
};

type Props = ComponentProps<'section'> & { variant?: Variant; flush?: boolean };

export function Card({ variant = 'tile', flush = false, className, ...rest }: Props) {
    const { look, room } = KINDS[variant];

    return <section className={cn(look, flush ? 'overflow-clip' : room, className)} {...rest} />;
}
