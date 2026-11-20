import { Tooltip as TooltipPrimitive } from 'radix-ui';
import { type ReactNode, useState } from 'react';

/*
 * The tooltip, on Radix's primitive as the popover is, for a control that shows no words of its
 * own: the collapsed sidebar's entries. The label shows beside the child on hover, after a short
 * pause so a pointer passing over a column of icons does not flash every label, and at once on
 * keyboard focus; it goes on Escape, on leaving and on blur. The child stays the element a press
 * and the keyboard reach, and the tooltip takes no place in the tab order. The library names the
 * label to a screen reader through the child's description as well.
 *
 * A control that shows its own words some of the time holds the tooltip shut for that time and
 * does not take it away, so the child stays the same element and keeps the focus as its words
 * come and go. Shut, the tooltip never opens and keeps nothing: whether it is open is held here,
 * an opening is refused and one already open is dropped. The library's memory of a label just
 * shown, which lets the next one skip the pause, is switched off, as a refused opening would set
 * it and nothing would clear it. So a pointer that rested on the child, or a focus still on it,
 * shows no label when the tooltip is let go: the label comes with the next hover or focus.
 *
 * The surface is the dark pill, the ink and the ground swapped so it reads over any page in
 * either theme, at the small type and never below it. It is portaled, the library's default, so
 * the sidebar's own scrolling cannot clip it, and it sits above the page as a popover does.
 */

type Props = {
    label: string;
    side?: 'top' | 'right' | 'bottom' | 'left';
    /** Held shut: nothing opens, whatever reaches the child, and nothing is kept for later. */
    disabled?: boolean;
    children: ReactNode;
};

/** How long a pointer rests before the label shows, in milliseconds. */
const DELAY = 300;

export function Tooltip({ label, side = 'right', disabled = false, children }: Props) {
    const [open, setOpen] = useState(false);

    if (disabled && open) {
        setOpen(false);
    }

    return (
        <TooltipPrimitive.Provider delayDuration={DELAY} skipDelayDuration={0}>
            <TooltipPrimitive.Root open={open && !disabled} onOpenChange={(next) => setOpen(next && !disabled)}>
                <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
                <TooltipPrimitive.Portal>
                    <TooltipPrimitive.Content side={side} sideOffset={10} collisionPadding={8} className="admin-tooltip z-50 rounded-full bg-foreground px-3 py-1.5 text-small font-semibold text-background shadow-float">
                        {label}
                    </TooltipPrimitive.Content>
                </TooltipPrimitive.Portal>
            </TooltipPrimitive.Root>
        </TooltipPrimitive.Provider>
    );
}
