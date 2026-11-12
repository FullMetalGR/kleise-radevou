import { DropdownMenu as MenuPrimitive } from 'radix-ui';
import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import { NestedOverlay } from './modal';

/*
 * The dropdown menu, the kit's first, on Radix's DropdownMenu as the popover stands on its
 * Popover: the Calendar's Add, and the Day column header's menu after it. A trigger the caller
 * hands over, an optional title heading the list (a label, not an item: it takes no focus and the
 * arrows pass it by), a list of items, each a label, an optional description under it and either
 * a handler or an href, and an optional note closing the list behind a rule, a muted line the
 * arrows pass by too.
 *
 * Keyboard-complete by the library: Enter, Space or the down arrow on the trigger opens it with
 * the first item focused, the arrows move between the items, Home and End reach the ends, typing
 * jumps to an item by its label, Enter or Space selects, and Escape closes and hands focus back
 * to the trigger. A handler item runs its handler and closes; an href item is a link, followed as
 * any link is.
 *
 * Placement and dismissal are the popover plumbing's: portaled out of the page at the trigger in
 * viewport coordinates, flipped and clamped by the library, no taller than the room on its side,
 * and counted up in an enclosing modal while open, so a backdrop press with the menu up closes
 * the menu alone.
 */

export type MenuItem = {
    /** Tells the items apart; never shown. */
    key: string;
    label: string;
    description?: string;
    className?: string;
} & ({ onSelect: () => void; href?: never } | { href: string; onSelect?: never });

type Props = {
    /** The control that opens the menu, a button the menu takes over as its trigger. */
    trigger: ReactElement;
    /** The menu's accessible name, in place of the trigger's text the library would name it by. */
    label: string;
    items: readonly MenuItem[];
    /** The line heading the list, not an item. */
    title?: string;
    /** A muted line closing the list behind a rule, not an item: what the items mean, where a word needs saying. */
    note?: string;
    align?: 'start' | 'center' | 'end';
    className?: string;
};

function Body({ item }: { item: MenuItem }) {
    return (
        <>
            <span className="admin-menu__label font-medium">{item.label}</span>
            {item.description !== undefined && <span className="admin-menu__description text-xs text-muted-foreground">{item.description}</span>}
        </>
    );
}

const ITEM = 'admin-menu__item flex cursor-default flex-col items-start gap-0.5 rounded-md px-3 py-2 text-left text-sm outline-none select-none data-[highlighted]:bg-accent';

export function DropdownMenu({ trigger, label, items, title, note, align = 'start', className }: Props) {
    return (
        <MenuPrimitive.Root modal={false}>
            <MenuPrimitive.Trigger asChild>{trigger}</MenuPrimitive.Trigger>
            <MenuPrimitive.Portal>
                <MenuPrimitive.Content
                    aria-label={label}
                    // The library points aria-labelledby at the trigger, which would win over the label.
                    aria-labelledby={undefined}
                    align={align}
                    sideOffset={4}
                    collisionPadding={8}
                    className={cn(
                        'admin-menu z-50 flex max-h-(--radix-dropdown-menu-content-available-height) min-w-(--radix-dropdown-menu-trigger-width) flex-col overflow-y-auto rounded-md border bg-background p-1 text-foreground shadow-md outline-none',
                        className,
                    )}
                    onEscapeKeyDown={(event) => {
                        if (event.isComposing) {
                            event.preventDefault();
                        }
                    }}
                >
                    <NestedOverlay />
                    {title !== undefined && <MenuPrimitive.Label className="admin-menu__title px-3 pt-1.5 pb-1 text-xs font-semibold text-muted-foreground">{title}</MenuPrimitive.Label>}
                    {items.map((item) =>
                        item.href !== undefined ? (
                            <MenuPrimitive.Item key={item.key} asChild className={cn(ITEM, item.className)}>
                                <a href={item.href}>
                                    <Body item={item} />
                                </a>
                            </MenuPrimitive.Item>
                        ) : (
                            <MenuPrimitive.Item key={item.key} className={cn(ITEM, item.className)} onSelect={item.onSelect}>
                                <Body item={item} />
                            </MenuPrimitive.Item>
                        ),
                    )}
                    {note !== undefined && (
                        <>
                            <MenuPrimitive.Separator className="admin-menu__separator my-1 border-t" />
                            <p className="admin-menu__note px-3 py-1.5 text-xs text-muted-foreground">{note}</p>
                        </>
                    )}
                </MenuPrimitive.Content>
            </MenuPrimitive.Portal>
        </MenuPrimitive.Root>
    );
}
