import { cn } from '@/lib/utils';

/*
 * The segmented control: one setting, two or three short mutually exclusive options, one joined
 * control with a group role and an accessible name. The chosen state lives in aria-pressed, not
 * in the fill, so the choice survives for anyone who cannot see the fill. A click chooses;
 * anything longer than three options is a select.
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
        <div role="group" aria-label={label} className={cn('admin-segmented inline-flex rounded-lg border p-0.5', className)}>
            {options.map((option) => {
                const chosen = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={chosen}
                        disabled={disabled}
                        className={cn(
                            'admin-segmented__option rounded-md px-3 py-1 text-sm disabled:cursor-not-allowed disabled:opacity-60',
                            chosen ? 'bg-primary font-medium text-primary-foreground' : 'hover:bg-muted',
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
