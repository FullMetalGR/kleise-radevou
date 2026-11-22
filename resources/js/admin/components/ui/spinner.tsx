import { cn } from '@/lib/utils';

/*
 * The admin's one spinner, for whatever loads outside a button (the button draws its own inside
 * its label). A ring of the current ink with its top quarter left open, turning only where the
 * person has not asked for less motion. Where they have, the ring stands still, and a labelled one
 * is still a status with its name for a screen reader. 24 pixels on its own, 14 inline in a line
 * of words.
 *
 * With a label it is a status named by that label, so a screen reader announces what is loading.
 * Without one it sits beside words that already say it, inside a status of the place's own, and is
 * hidden from assistive technology. The place adds its own classes, such as the room beside it.
 */

type Size = 'default' | 'inline';

const SIZES: Record<Size, string> = {
    default: 'block size-6',
    inline: 'inline-block size-3.5',
};

export function Spinner({ label, size = 'default', className }: { label?: string; size?: Size; className?: string }) {
    const named = label !== undefined && label !== '';

    return (
        <span
            className={cn('admin-spinner shrink-0 rounded-full border-2 border-current border-t-transparent motion-safe:animate-spin', SIZES[size], className)}
            role={named ? 'status' : undefined}
            aria-label={named ? label : undefined}
            aria-hidden={named ? undefined : true}
        />
    );
}
