import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/*
 * The select, native underneath: flat options first, then optional groups. A group with no
 * options renders nothing, not an empty label.
 */

type SelectOption = { readonly value: string; readonly label: string };

type SelectGroup = { readonly label: string; readonly options: readonly SelectOption[] };

type Props = Omit<ComponentProps<'select'>, 'value' | 'onChange' | 'children'> & {
    options: readonly SelectOption[];
    groups?: readonly SelectGroup[];
    value: string;
    onChange: (value: string) => void;
};

export function Select({ options, groups = [], value, onChange, className, ...rest }: Props) {
    return (
        <select
            className={cn('admin-select h-8 rounded-md border bg-background px-3 py-1 text-sm aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-60', className)}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            {...rest}
        >
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
            {groups
                .filter((group) => group.options.length > 0)
                .map((group) => (
                    <optgroup key={group.label} label={group.label}>
                        {group.options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </optgroup>
                ))}
        </select>
    );
}
