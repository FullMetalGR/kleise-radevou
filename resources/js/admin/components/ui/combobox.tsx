import { useId, useRef, useState, type ComponentProps, type KeyboardEvent } from 'react';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { Popover, PopoverAnchor, PopoverContent } from './popover';

/*
 * The combobox, the one control with no native counterpart: it shows a person's name and returns
 * an id. The rule it is built around is that the value changes only when somebody picks an option.
 * Typing filters and nothing more; a combobox guessing from a partial match would one day link the
 * wrong account and nothing downstream would notice.
 *
 * Closed it shows the chosen label, open it shows the query. ArrowDown and ArrowUp open the list
 * and wrap, Enter chooses the highlighted option, Escape closes and clears the query. Options are
 * chosen on mousedown, with its default refused so focus stays in the input: on click, the
 * input's blur would close the list before the click landed. A blur or a press outside is an
 * abandoned search, closing and clearing, showing what is still chosen. Clear shows whenever
 * something is chosen.
 *
 * shadcn's combobox recipe rests on a command palette that chooses on click and filters its own
 * items; this keeps the recipe's shape, an input over a listbox in a popover, and builds the
 * choosing and the filtering to the rules above. The list sits in the shared popover, anchored at
 * the input and its Clear, which open it themselves, so a press on them is not a press outside;
 * focus stays in the input both ways. Escape reaches the popover alone as the top layer, so inside
 * a modal it closes the list and leaves the modal open. Clear and the empty list's line are the
 * kit's words, from the strings the control is given.
 */

type ComboboxOption<T> = { readonly value: T; readonly label: string };

type Props<T> = Omit<ComponentProps<'input'>, 'value' | 'onChange' | 'type' | 'role' | 'children'> & {
    options: readonly ComboboxOption<T>[];
    value: T | null;
    onChange: (value: T | null) => void;
};

export function Combobox<T extends string | number>({ options, value, onChange, className, disabled, ...rest }: Props<T>) {
    const shell = useShell();
    const listId = useId();
    const anchor = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [highlight, setHighlight] = useState(0);

    const chosen = options.find((option) => option.value === value);
    const needle = query.trim().toLowerCase();
    const shown = needle === '' ? options : options.filter((option) => option.label.toLowerCase().includes(needle));
    const active = open ? shown[highlight] : undefined;

    const abandon = () => {
        setOpen(false);
        setQuery('');
    };

    const choose = (option: ComboboxOption<T>) => {
        abandon();
        onChange(option.value);
    };

    const openAt = () => {
        const at = shown.findIndex((option) => option.value === value);

        setHighlight(Math.max(at, 0));
        setOpen(true);
    };

    const step = (by: number) => {
        if (!open) {
            openAt();
        } else if (shown.length > 0) {
            setHighlight((at) => (at + by + shown.length) % shown.length);
        }
    };

    const keys = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            step(event.key === 'ArrowDown' ? 1 : -1);
        } else if (event.key === 'Enter' && active !== undefined) {
            event.preventDefault();
            choose(active);
        }
    };

    return (
        <Popover open={open} onOpenChange={(next) => (next ? undefined : abandon())}>
            <PopoverAnchor asChild>
                <div ref={anchor} className={cn('admin-combobox flex items-center gap-1', className)}>
                    <input
                        type="text"
                        role="combobox"
                        autoComplete="off"
                        aria-autocomplete="list"
                        aria-expanded={open}
                        aria-controls={listId}
                        aria-activedescendant={active === undefined ? undefined : `${listId}-${String(active.value)}`}
                        className="admin-combobox__input admin-input h-8 w-full rounded-md border bg-background px-3 py-1 text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-60"
                        disabled={disabled}
                        value={open ? query : (chosen?.label ?? '')}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setHighlight(0);
                            setOpen(true);
                        }}
                        onClick={() => (open ? undefined : openAt())}
                        onKeyDown={keys}
                        onBlur={abandon}
                        {...rest}
                    />
                    {value !== null && !disabled && (
                        <button type="button" className="admin-combobox__clear shrink-0 rounded-md px-2 py-1 text-sm text-muted-foreground hover:text-foreground" onClick={() => onChange(null)}>
                            {shell.text('kit.combobox.clear')}
                        </button>
                    )}
                </div>
            </PopoverAnchor>
            <PopoverContent
                role="presentation"
                className="max-h-[min(18rem,var(--radix-popover-content-available-height))]"
                onOpenAutoFocus={(event) => event.preventDefault()}
                onCloseAutoFocus={(event) => event.preventDefault()}
                onInteractOutside={(event) => {
                    if (event.target instanceof Node && anchor.current?.contains(event.target)) {
                        event.preventDefault();
                    }
                }}
            >
                <Options listId={listId} shown={shown} value={value} active={active} emptyLabel={shell.text('kit.combobox.no_matches')} onChoose={choose} />
            </PopoverContent>
        </Popover>
    );
}

type OptionsProps<T> = {
    listId: string;
    shown: readonly ComboboxOption<T>[];
    value: T | null;
    active: ComboboxOption<T> | undefined;
    emptyLabel: string;
    onChoose: (option: ComboboxOption<T>) => void;
};

/**
 * The filtered options, each chosen on mousedown with its default refused, so the input keeps
 * focus and its blur cannot close the list before the choice lands.
 */
function Options<T extends string | number>({ listId, shown, value, active, emptyLabel, onChoose }: OptionsProps<T>) {
    if (shown.length === 0) {
        return <p className="admin-combobox__empty px-3 py-2 text-sm text-muted-foreground">{emptyLabel}</p>;
    }

    return (
        <ul role="listbox" id={listId} className="admin-combobox__list py-1">
            {shown.map((option) => (
                <li
                    key={String(option.value)}
                    id={`${listId}-${String(option.value)}`}
                    role="option"
                    aria-selected={option.value === value}
                    data-highlighted={option === active ? '' : undefined}
                    className="admin-combobox__option cursor-pointer px-3 py-1.5 text-sm data-highlighted:bg-accent aria-selected:font-semibold"
                    onMouseDown={(event) => {
                        event.preventDefault();
                        onChoose(option);
                    }}
                >
                    {option.label}
                </li>
            ))}
        </ul>
    );
}
