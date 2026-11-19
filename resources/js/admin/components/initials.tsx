import { cn } from '@/lib/utils';

/*
 * A member's initials as the Calendar and Shifts draw a member: a circle with the letters in the
 * display face at 700, at a column head's size, a row's or a list's (the Who popover's rows). A
 * member's hue rings the circle on the card's colour, the ring drawn inside its edge by the
 * element's own shadow, so the hue needs no rule and the circle keeps its size; the head's ring
 * is the widest. One with no hue of its own sits on the theme's strong tint in the deepened theme
 * colour, which reads on that tint in every theme.
 */

const SIZES = {
    head: { className: 'size-11.5 text-label', ring: 3 },
    row: { className: 'size-8 text-xs', ring: 2 },
    list: { className: 'size-7.5 text-xs', ring: 2 },
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

    return (
        <span
            className={cn('flex shrink-0 items-center justify-center rounded-full font-display font-bold', hue === undefined ? 'bg-tint-strong text-primary-deep' : 'bg-card text-foreground', sized, className)}
            style={hue === undefined ? undefined : { boxShadow: `inset 0 0 0 ${ring}px ${hue}` }}
            aria-hidden="true"
        >
            {initialsOf(name)}
        </span>
    );
}
