import type { MouseEvent } from 'react';
import { cn } from '@/lib/utils';
import { Button } from './button';

/*
 * The row select button. Every table's identifying cell carries one: a link-styled button whose
 * accessible name is the row's name, marked current when its row is selected. The row keeps a
 * click handler purely as a larger mouse target; this button stops the click there, so one click
 * selects once. It exists because a bare row click is invisible to everything but a mouse.
 *
 * Beside the button's own focus outline it takes the soft fill under the pointer, with a little
 * room either side of the name that the same room taken back from its margins keeps in line with
 * the column's other cells.
 */

type Props = {
    name: string;
    selected: boolean;
    onSelect: () => void;
    className?: string;
};

export function RowSelectButton({ name, selected, onSelect, className }: Props) {
    const click = (event: MouseEvent<HTMLButtonElement>) => {
        event.stopPropagation();
        onSelect();
    };

    return (
        <Button variant="link" className={cn('admin-row-select -mx-1.5 px-1.5 py-0.5 text-left enabled:hover:bg-muted', className)} aria-current={selected ? true : undefined} onClick={click}>
            {name}
        </Button>
    );
}
