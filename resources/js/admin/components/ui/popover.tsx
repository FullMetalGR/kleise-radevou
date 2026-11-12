import { Popover as PopoverPrimitive } from 'radix-ui';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';
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
 * the library's default, so a scrolled modal body cannot clip it.
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

export function PopoverContent({ className, align = 'start', sideOffset = 4, collisionPadding = 8, onEscapeKeyDown, children, ...rest }: ComponentProps<typeof PopoverPrimitive.Content>) {
    return (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                align={align}
                sideOffset={sideOffset}
                collisionPadding={collisionPadding}
                className={cn(
                    'admin-popover z-50 max-h-(--radix-popover-content-available-height) min-w-(--radix-popover-trigger-width) overflow-y-auto rounded-md border bg-background text-foreground shadow-md outline-none',
                    className,
                )}
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
