import type { ComponentProps } from 'react';
import { Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Field } from './field';
import { TextInput } from './input';

/*
 * The search field of a list: the field shell with its label shown above, around a text input of
 * the search type drawn as a pill at the field's height, a search icon at its start in the muted
 * ink. The icon is decoration, hidden from assistive technology; the label names the input. It
 * filters as the place filters, on what the input hands back, and has no clear button: emptying
 * the input is how a search is cleared. The field takes the row's whole width up to a readable
 * measure.
 *
 * Every attribute the place passes reaches the input, its class beside the pill's own, so the
 * place keeps its own hook on the input.
 */

type Props = { id: string; label: string; className?: string } & Omit<ComponentProps<'input'>, 'id' | 'type'>;

export function SearchField({ id, label, className, ...input }: Props) {
    return (
        <Field id={id} label={label} className="admin-search-field w-full max-w-120">
            {(control) => (
                <div className="relative">
                    <Search className="pointer-events-none absolute start-4 top-1/2 size-4.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                    <TextInput {...control} {...input} type="search" className={cn('rounded-full ps-11', className)} />
                </div>
            )}
        </Field>
    );
}
