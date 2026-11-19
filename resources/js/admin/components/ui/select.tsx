import type { ComponentProps } from 'react';
import { SelectChevron } from '../glyphs';
import { FIELD, FIELD_HEIGHT, PRESSED_HOVER } from './input';
import { cn } from '@/lib/utils';

/*
 * The select, native underneath: flat options first, then optional groups. A group with no
 * options renders nothing, not an empty label. It wears the field's look with the browser's own
 * arrow taken off and a chevron drawn at the right in its place, over 40px of padding the label
 * never runs into; the chevron lets a press through to the select and dims with it. The frame's
 * one column is as wide as the frame and no wider, and the select shrinks into it, so the longest
 * option never widens the select past the place it stands in.
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
        <div className="admin-select__frame grid grid-cols-1">
            <select
                className={cn('admin-select peer col-start-1 row-start-1 min-w-0 appearance-none', FIELD, PRESSED_HOVER, FIELD_HEIGHT, 'pr-10', className)}
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
            <span className="pointer-events-none col-start-1 row-start-1 mr-3.5 self-center justify-self-end text-muted-foreground peer-disabled:opacity-45">
                <SelectChevron />
            </span>
        </div>
    );
}
