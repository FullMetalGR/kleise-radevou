import { Dialog as DialogPrimitive } from 'radix-ui';
import { createContext, useContext, useEffect, useRef, type PointerEvent, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

/*
 * The modal, shadcn/ui's dialog on Radix, copied in and edited where the admin needs it to differ
 * from the primitive. Backdrop and frame, the dialog role, aria-modal, a title, and an optional
 * footer that pins its actions outside the scrolling body so a long body cannot push them away.
 * Focus moves into the frame on open, stays there under Tab and Shift+Tab, and goes back on close
 * to whatever held it at open. Escape closes.
 *
 * Two edits to the primitive. A backdrop press closes only when it both starts and ends on the
 * backdrop, so releasing a text selection outside the frame cannot dismiss it and throw away what
 * was typed; the primitive dismisses on a pointer down outside, so that is refused and the two
 * ends are watched on the backdrop, which wraps the frame. And the focus return: the primitive
 * returns focus to its own trigger, which a modal opened from a screen's state does not have, so
 * the modal notes what held focus as it opened and hands it back.
 *
 * Escape across stacked overlays: a parent ignores close requests while a nested overlay is up,
 * and one press peels exactly one layer. The primitive keeps a stack of layers and hands Escape,
 * which it hears on the document in the capture phase, to the top one alone, so a nested modal or
 * a popover closes and the modal under it never hears the press.
 *
 * The backdrop close is ours, so the stack does not guard it: every layer a press is outside of
 * hears it, and the popover closes on the click that ends the press, after the modal has seen
 * both of its ends. So a popover counts itself up through NestedOverlay while it is open, and
 * a press begun while one is up cannot close the modal: one press on the backdrop peels the
 * popover, the next the modal.
 */

/*
 * Three shapes, every one the same dialog with the same layers and closes. The centred frame is
 * the default, bounded to the viewport at every width. Filling is that frame at or above the
 * admin's breakpoint and the whole viewport below it, the footer still pinned and the body still
 * the one scroller: the booking dialog and the wizard on a phone. The drawer is pinned to the
 * start edge at the viewport's full height: the rail behind the top bar's control.
 */
type ModalVariant = 'centred' | 'filling' | 'drawer';

const BACKDROP: Record<ModalVariant, string> = {
    centred: 'items-center justify-center p-4',
    filling: 'items-center justify-center p-4 max-desktop:p-0',
    drawer: 'items-stretch justify-start',
};

const FRAME: Record<ModalVariant, string> = {
    centred: 'max-h-[85vh] max-w-lg rounded-xl',
    filling: 'admin-modal__frame--filling max-h-[85vh] max-w-lg rounded-xl max-desktop:h-full max-desktop:max-h-none max-desktop:max-w-none max-desktop:rounded-none max-desktop:border-0',
    drawer: 'admin-modal__frame--drawer h-full max-w-72 rounded-none border-y-0 border-l-0',
};

type Enclosure = {
    register: () => () => void;
};

const Enclosing = createContext<Enclosure | null>(null);

/**
 * Counts this overlay as up inside the nearest enclosing modal while `open` holds, so that modal
 * ignores a backdrop press begun until it is gone; outside any modal it does nothing.
 */
function useNestedOverlay(open: boolean): void {
    const enclosure = useContext(Enclosing);

    useEffect(() => (open && enclosure !== null ? enclosure.register() : undefined), [open, enclosure]);
}

/**
 * Counts an open overlay up in the enclosing modal, rendering nothing: mounted inside a popover's
 * or a menu's content, it is up exactly while that content is.
 */
export function NestedOverlay() {
    useNestedOverlay(true);

    return null;
}

type Props = {
    open: boolean;
    title: string;
    onClose: () => void;
    footer?: ReactNode;
    /** The frame's shape; the centred frame unless told. */
    variant?: ModalVariant;
    className?: string;
    children: ReactNode;
};

export function Modal({ open, title, onClose, footer, variant = 'centred', className, children }: Props) {
    const nested = useRef(0);
    const opener = useRef<Element | null>(null);
    const downOnBackdrop = useRef(false);
    const enclosure = useRef<Enclosure>({
        register: () => {
            nested.current += 1;

            return () => {
                nested.current -= 1;
            };
        },
    });

    const escape = (event: KeyboardEvent) => {
        if (event.isComposing) {
            event.preventDefault();
        }
    };

    const down = (event: PointerEvent<HTMLDivElement>) => {
        downOnBackdrop.current = event.target === event.currentTarget && nested.current === 0;
    };

    const up = (event: PointerEvent<HTMLDivElement>) => {
        const both = downOnBackdrop.current && event.target === event.currentTarget;

        downOnBackdrop.current = false;

        if (both && nested.current === 0) {
            onClose();
        }
    };

    const giveFocusBack = (event: Event) => {
        event.preventDefault();

        if (opener.current instanceof HTMLElement && opener.current.isConnected) {
            opener.current.focus();
        }
    };

    return (
        <DialogPrimitive.Root open={open} onOpenChange={(next) => (next ? undefined : onClose())}>
            <DialogPrimitive.Portal>
                <Enclosing.Provider value={enclosure.current}>
                    <DialogPrimitive.Overlay className={cn('admin-modal__backdrop fixed inset-0 z-50 flex bg-black/40', BACKDROP[variant])} onPointerDown={down} onPointerUp={up}>
                        <DialogPrimitive.Content
                            aria-modal="true"
                            aria-describedby={undefined}
                            className={cn('admin-modal__frame flex w-full flex-col border bg-background text-foreground shadow-lg outline-none', FRAME[variant], className)}
                            onOpenAutoFocus={() => {
                                opener.current = document.activeElement;
                            }}
                            onCloseAutoFocus={giveFocusBack}
                            onEscapeKeyDown={escape}
                            onInteractOutside={(event) => event.preventDefault()}
                        >
                            <DialogPrimitive.Title className="admin-modal__title shrink-0 border-b px-5 py-3 text-lg font-semibold">{title}</DialogPrimitive.Title>
                            <div className="admin-modal__body min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>
                            {footer !== undefined && <div className="admin-modal__footer flex shrink-0 justify-end gap-2 border-t px-5 py-3">{footer}</div>}
                        </DialogPrimitive.Content>
                    </DialogPrimitive.Overlay>
                </Enclosing.Provider>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
