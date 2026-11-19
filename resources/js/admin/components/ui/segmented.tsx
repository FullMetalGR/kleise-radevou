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
 */

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
        <div role="group" aria-label={label} className={cn('admin-segmented inline-flex flex-wrap gap-0.5 rounded-inner bg-card p-1 inset-ring inset-ring-border', className)}>
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
                            chosen ? 'bg-foreground text-background' : 'text-muted-foreground enabled:hover:bg-muted enabled:hover:text-foreground',
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
