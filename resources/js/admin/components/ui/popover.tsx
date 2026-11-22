import { Popover as PopoverPrimitive } from 'radix-ui';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';
import { FORCED_EDGE } from './input';
import { NestedOverlay } from './modal';

/*
 * The popover, shadcn/ui's popover on Radix, copied in and edited where the admin needs it to
 * differ from the recipe, the one surface the combobox, the date field and the time field open.
 * The primitive the modal stands on, so no dependency beyond the modal's.
 *
 * Placement is the library's: at the trigger, in viewport coordinates, flipped when the room
 * below runs out, clamped inside the viewport, and following scroll and resize through its own
 * update. The content is at least the trigger's width and no taller than the room on its side,
 * through the variables the library exposes, so a long list scrolls inside it. It is portaled,
 * the library's default, so a scrolled modal body cannot clip it. It opens on the menu surface
 * below, as the dropdown menu does, so every list and panel that opens over a page looks alike.
 *
 * Dismissal is the library's layer stack: Escape reaches the top layer alone, so inside a modal
 * one press peels the popover and the next the modal. An Escape that is composing text is
 * refused, as the modal refuses it. A press outside closes it, deferred by the library to the
 * click; the modal's own backdrop close is ours, a press begun and released on the backdrop, so
 * the content counts itself up in the enclosing modal through NestedOverlay while it is
 * mounted, and a backdrop press with a popover up closes the popover alone.
 *
 * Focus moves into the content on open and back to the trigger on close, unless the close came
 * from a press outside, when it stays on what was pressed. A consumer that keeps focus elsewhere,
 * the combobox in its input, refuses both moves.
 */

export const Popover = PopoverPrimitive.Root;

export const PopoverTrigger = PopoverPrimitive.Trigger;

export const PopoverAnchor = PopoverPrimitive.Anchor;

/**
 * The surface every popover and dropdown menu opens on: the popover fill, the menu's rounding and
 * the floating shadow, whose own hairline stands in for a border, with a little room inside the
 * edge so a row's own rounding shows; where the system forces its own colours the shadow is
 * dropped and a real line takes its place. A content that wants more room inside says so.
 */
export const MENU_SURFACE = `rounded-menu border-0 bg-popover p-1.5 text-popover-foreground shadow-float ${FORCED_EDGE}`;

/**
 * A row of a pick list: 44px, rounded as a field is, the soft fill on the keyboard's active row
 * alone. The lists move that row by the keyboard and never by the pointer, so a fill under the
 * pointer as well would show two filled rows at once. The chosen row is heavier and carries a tick
 * at its end, the tick alone telling it apart once a row is also active. A dropdown menu's items
 * wear the same row, all of them heavier; there the library moves the active row with the pointer
 * too, so the one fill follows the pointer as it follows the arrows.
 */
export const MENU_OPTION = 'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-field px-3.5 text-body data-highlighted:bg-accent aria-selected:font-semibold';

export function PopoverContent({ className, align = 'start', sideOffset = 4, collisionPadding = 8, onEscapeKeyDown, children, ...rest }: ComponentProps<typeof PopoverPrimitive.Content>) {
    return (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                align={align}
                sideOffset={sideOffset}
                collisionPadding={collisionPadding}
                className={cn('admin-popover z-50 max-h-(--radix-popover-content-available-height) min-w-(--radix-popover-trigger-width) overflow-y-auto outline-none', MENU_SURFACE, className)}
                onEscapeKeyDown={(event) => {
                    if (event.isComposing) {
                        event.preventDefault();
                    }

                    onEscapeKeyDown?.(event);
                }}
                {...rest}
            >
                <NestedOverlay />
                {children}
            </PopoverPrimitive.Content>
        </PopoverPrimitive.Portal>
    );
}
