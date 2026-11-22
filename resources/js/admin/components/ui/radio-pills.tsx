import type { ReactNode } from 'react';
import { FORCED_EDGE } from './input';
import { CHOSEN } from './segmented';
import { cn } from '@/lib/utils';

/*
 * The radio pills: one setting of a few short choices, all of them in view. It is a fieldset named
 * by its legend, each choice a pill whose label holds a native radio hidden from sight, so the
 * browser's own radio group does the keyboard's work: the group is one tab stop and the arrow keys
 * move the choice, firing the change a click fires. The chosen pill is filled in the ink with the
 * page's colour for its words, the others sit on the card inside a line ring that darkens under
 * the pointer, and the focus outline the keyboard shows lands on the pill, since the radio inside
 * it is not drawn. A held group disables every radio and dims every pill. The pills wrap onto more
 * rows where one does not hold them.
 *
 * A place that names the group for itself passes its class, and a line that explains the choice
 * goes in as children, under the pills and inside the fieldset.
 */

type RadioPillsOption<T extends string> = { readonly value: T; readonly label: string };

type Props<T extends string> = {
    name: string;
    legend: string;
    value: T;
    options: readonly RadioPillsOption<T>[];
    disabled?: boolean;
    onChange: (value: T) => void;
    className?: string;
    children?: ReactNode;
};

/**
 * A pill's look; the chosen one's fill is added beside it. Where the system forces its own colours
 * every pill takes a real line, the chosen one's in its highlight, so choosing one moves none, and
 * the keyboard's outline takes the system's colour for text, which the chosen pill would otherwise
 * keep in the theme colour.
 */
const PILL = `admin-radio-pills__option inline-flex h-11 cursor-pointer items-center rounded-full px-4.5 text-body-sm font-semibold has-focus-visible:outline-3 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary forced-colors:has-focus-visible:outline-[CanvasText] has-disabled:cursor-not-allowed has-disabled:opacity-45 motion-safe:transition-colors motion-safe:duration-200 ${FORCED_EDGE}`;

export function RadioPills<T extends string>({ name, legend, value, options, disabled = false, onChange, className, children }: Props<T>) {
    return (
        <fieldset className={cn('admin-radio-pills flex min-w-0 flex-col gap-2', className)}>
            <legend className="admin-radio-pills__legend mb-2 text-label text-muted-foreground">{legend}</legend>
            <div className="flex flex-wrap gap-2.5">
                {options.map((option) => {
                    const chosen = option.value === value;

                    return (
                        <label key={option.value} className={cn(PILL, chosen ? CHOSEN : 'bg-card text-foreground inset-ring-[1.5px] inset-ring-border has-enabled:hover:inset-ring-ink-tertiary')}>
                            <input type="radio" className="sr-only" name={name} value={option.value} checked={chosen} disabled={disabled} onChange={() => onChange(option.value)} />
                            {option.label}
                        </label>
                    );
                })}
            </div>
            {children}
        </fieldset>
    );
}
