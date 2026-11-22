import { cn } from '@/lib/utils';

/*
 * A person's initials: a circle with the letters in the display face at 700. A member, as the
 * Calendar and Shifts draw one, comes at a column head's size, a row's or a list's (the Who
 * popover's rows); a customer at a list row's size or at the head of their own page. A member's
 * hue rings the circle on the card's colour, the ring drawn inside its edge by the element's own
 * shadow, so the hue needs no rule and the circle keeps its size; the head's ring is the widest.
 * A customer's circle has no ring and ignores a hue. A circle with no ring sits on the theme's
 * strong tint in the deepened theme colour, which reads on that tint in every theme, so it never
 * draws card on card. Where the system forces its own colours the shadow is dropped, so there a
 * ringed circle takes an outline inside its edge in the system's colour instead.
 */

/** A ringed circle's edge where the system forces its own colours. */
const FORCED_RING = 'forced-colors:outline-2 forced-colors:-outline-offset-2';

const SIZES = {
    head: { className: 'size-11.5 text-label', ring: 3 },
    row: { className: 'size-8 text-xs', ring: 2 },
    list: { className: 'size-7.5 text-xs', ring: 2 },
    person: { className: 'size-10 text-sm', ring: 0 },
    hero: { className: 'size-19 text-2xl', ring: 0 },
} as const;

/** A member's initials: the first letter of their first and last words, upper-cased. */
export function initialsOf(name: string): string {
    const words = name.trim().split(/\s+/).filter((word) => word !== '');
    const first = words[0] ?? '';
    const last = words.length > 1 ? (words[words.length - 1] ?? '') : '';

    return `${[...first][0] ?? ''}${[...last][0] ?? ''}`.toUpperCase();
}

export function Initials({ name, hue, size, className }: { name: string; hue?: string; size: keyof typeof SIZES; className?: string }) {
    const { className: sized, ring } = SIZES[size];
    const ringed = hue !== undefined && ring > 0;

    return (
        <span
            className={cn('flex shrink-0 items-center justify-center rounded-full font-display font-bold', ringed ? ['bg-card text-foreground', FORCED_RING] : 'bg-tint-strong text-primary-deep', sized, className)}
            style={ringed ? { boxShadow: `inset 0 0 0 ${ring}px ${hue}` } : undefined}
            aria-hidden="true"
        >
            {initialsOf(name)}
        </span>
    );
}
