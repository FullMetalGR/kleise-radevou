import { SlidersHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from './ui/button';

/*
 * The button that folds a screen's filters away on a phone, where a card of fields one to a row
 * would push the screen's content under the fold. It is the kit's button at the full 44px, its
 * label handed in, naming the card it opens by id and saying whether that card is open. Where some
 * filters are set it wears how many on a small round after the label, the figure kept from a
 * screen reader, which hears the name handed in with it instead.
 */

type Props = {
    open: boolean;
    onToggle: () => void;
    /** The id of the card the button opens and closes. */
    controls: string;
    label: string;
    /** How many filters are set; nothing is drawn at zero. */
    count: number;
    /** The count as a screen reader hears it. */
    countName: string;
    className?: string;
};

export function FiltersToggle({ open, onToggle, controls, label, count, countName, className }: Props) {
    return (
        <Button icon={<SlidersHorizontal />} aria-expanded={open} aria-controls={controls} onClick={onToggle} className={cn('admin-filters-toggle shrink-0', className)}>
            {label}
            {count > 0 && (
                <>
                    <span aria-hidden="true" className="admin-filters-toggle__count inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs leading-5 font-semibold text-primary-foreground tabular-nums">
                        {count}
                    </span>
                    <span className="sr-only">{`, ${countName}`}</span>
                </>
            )}
        </Button>
    );
}
