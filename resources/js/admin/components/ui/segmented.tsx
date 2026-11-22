import { REACH } from './button';
import { FORCED_EDGE } from './input';
import { cn } from '@/lib/utils';

/*
 * The segmented control: one setting, two or three short mutually exclusive options, one joined
 * control with a group role and an accessible name. It is a rounded group on the card with a line
 * round it, each segment a pill, the chosen one filled in the ink with the page's colour for its
 * words. Where one row does not fit, the segments wrap onto a second and the group never scrolls
 * inside itself, so its corners take a fixed rounding: round enough to read as a pill around one
 * row, and not a tall capsule around two. The chosen state lives in aria-pressed as well as in the
 * fill, so the choice survives for anyone who cannot see the fill. A click chooses; anything
 * longer than three options is a select.
 *
 * Each segment is drawn 38px high and answers a touch 44px high in the compact layout, reaching
 * 3px over and under itself; there two wrapped rows stand 6px apart, so the reaches of two rows
 * touch and never overlap.
 */

/**
 * A chosen option where the system forces its own colours. There a fill is repainted as the page
 * and the choice would vanish, so it takes the system's own highlight and its text colour, kept
 * from being repainted, under the pointer too, with a line round it, where it has one, in the
 * same highlight. Kept from being repainted, its focus outline would keep the theme colour, which
 * can all but vanish on the system's page, so the outline takes the system's colour for text.
 */
export const FORCED_CHOSEN =
    'forced-colors:border-[Highlight] forced-colors:bg-[Highlight] forced-colors:text-[HighlightText] forced-colors:forced-color-adjust-none forced-colors:enabled:hover:bg-[Highlight] forced-colors:focus-visible:outline-[CanvasText]';

/** The fill of a chosen segment, pill or tab: the ink with the page's colour for its words. */
export const CHOSEN = `bg-foreground text-background ${FORCED_CHOSEN}`;

type SegmentedOption<T extends string> = { readonly value: T; readonly label: string };

type Props<T extends string> = {
    label: string;
    options: readonly SegmentedOption<T>[];
    value: T;
    onChange: (value: T) => void;
    disabled?: boolean;
    className?: string;
};

export function Segmented<T extends string>({ label, options, value, onChange, disabled = false, className }: Props<T>) {
    return (
        <div role="group" aria-label={label} className={cn('admin-segmented inline-flex flex-wrap gap-0.5 rounded-inner bg-card p-1 inset-ring inset-ring-border max-wide:gap-y-1.5', FORCED_EDGE, className)}>
            {options.map((option) => {
                const chosen = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={chosen}
                        disabled={disabled}
                        className={cn(
                            'admin-segmented__option focus-ring h-9.5 rounded-full px-4.5 text-body-sm font-semibold whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-60 motion-safe:transition-colors',
                            REACH,
                            chosen ? CHOSEN : 'text-muted-foreground enabled:hover:bg-muted enabled:hover:text-foreground',
                        )}
                        onClick={() => onChange(option.value)}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
