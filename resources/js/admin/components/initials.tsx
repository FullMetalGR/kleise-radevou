import { cn } from '@/lib/utils';

/*
 * A member's initials as the Calendar and Shifts draw a member: a circle in the card's colour,
 * ringed in the member's hue over a border of the card's colour, at a column head's size or at a
 * list's (the Who popover's rows). The ring is the element's own shadow, so the hue needs no rule.
 */

const SIZES = {
    head: { className: 'size-9 border-2 text-xs', ring: 2 },
    row: { className: 'size-8 border-2 text-xs', ring: 2 },
    list: { className: 'size-6 border text-[11px]', ring: 1 },
} as const;

/** A member's initials: the first letter of their first and last words, upper-cased. */
export function initialsOf(name: string): string {
    const words = name.trim().split(/\s+/).filter((word) => word !== '');
    const first = words[0] ?? '';
    const last = words.length > 1 ? (words[words.length - 1] ?? '') : '';

    return `${[...first][0] ?? ''}${[...last][0] ?? ''}`.toUpperCase();
}

export function Initials({ name, hue, size, className }: { name: string; hue: string; size: keyof typeof SIZES; className?: string }) {
    const { className: sized, ring } = SIZES[size];

    return (
        <span className={cn('flex shrink-0 items-center justify-center rounded-full border-card bg-card font-semibold', sized, className)} style={{ boxShadow: `0 0 0 ${ring}px ${hue}` }} aria-hidden="true">
            {initialsOf(name)}
        </span>
    );
}
