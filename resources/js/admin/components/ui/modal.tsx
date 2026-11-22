import { Dialog as DialogPrimitive } from 'radix-ui';
import { createContext, useContext, useEffect, useRef, type PointerEvent, type ReactNode } from 'react';
import { Cross } from '../glyphs';
import { Button } from './button';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';

/*
 * The modal, shadcn/ui's dialog on Radix, copied in and edited where the admin needs it to differ
 * from the primitive. Backdrop and frame, the dialog role, aria-modal, a title, and an optional
 * footer that pins its actions outside the scrolling body so a long body cannot push them away.
 * The actions are set against the footer's end edge and no button gives up width, so a row of
 * them wider than a phone would run out of the start edge, where no scroll reaches: below the
 * admin's breakpoint the row wraps, every line still ending at the end edge.
 * Focus moves into the frame on open, stays there under Tab and Shift+Tab, and goes back on close
 * to whatever held it at open. Escape closes. The page behind is hidden from assistive technology
 * while the frame is up, which the primitive does for a modal dialog.
 *
 * A dialog drawn as a card carries a round close control at the end of its title's row. It comes
 * last in the frame and is placed over the row, so focus still lands first on the body's own field
 * or the footer's first action, and it closes as Escape does, through the same close request. It
 * is drawn at 40 pixels and takes a press 2 pixels past each side, a touch area of 44. A dialog
 * whose act is in flight says it is busy, and the control is then disabled, as the footer's
 * buttons are, so it never stands live over a close request its owner would swallow; what Escape
 * and a backdrop press do meanwhile is still the owner's own.
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
 * Four shapes, every one the same dialog with the same layers and closes, over the scrim, blurred.
 * The centred frame is the default: a card no wider than 780 pixels, kept 24 pixels clear of the
 * viewport's edges, and below the admin's breakpoint a sheet at the bottom edge, the screen's
 * width, rounded at the top alone and never taller than 94 percent of the screen, so a small
 * dialog such as a confirm sits under the thumb. Filling is that card at or above the breakpoint
 * and the whole viewport below it, the footer still pinned and the body still the one scroller:
 * the booking dialog and the wizard on a phone. Full is the whole viewport at every width, the
 * footer pinned and the body the one scroller: the setup wizard, which is a screen of its own
 * while it is up. What its title row, its body and its footer hold keeps to one column no wider
 * than 62.5rem, centred, while the frame, the scroll and the footer's rule still span the window,
 * and what the body holds is the query container its parts measure themselves against. The drawer is a panel pinned to the start edge at the viewport's full height,
 * 340 pixels wide or 88 parts in a hundred of a narrower screen, rounded at its far side, sliding
 * in from the edge where motion is welcome: the menu behind the first row's Menu button. Its title
 * is for a screen reader alone, as what it holds heads itself. A caller's own width cap wins over
 * the card's.
 *
 * A frame changes shape by its classes alone, so a window crossing the breakpoint with a dialog
 * up keeps the same frame, what was typed in it and the focus. Every frame is on the card's fill.
 * A card rises a little and grows from just under its size as it opens, a sheet from further down,
 * and the scrim fades in, each only where motion is welcome.
 */
type ModalVariant = 'centred' | 'filling' | 'full' | 'drawer';

const BACKDROP: Record<ModalVariant, string> = {
    centred: 'items-center justify-center p-6 max-desktop:p-0',
    filling: 'items-center justify-center p-6 max-desktop:p-0',
    full: 'items-stretch justify-stretch',
    drawer: 'items-stretch justify-start',
};

/** The card both the centred and the filling frame are at or above the breakpoint. */
const CARD = 'max-h-[calc(100dvh-3rem)] max-w-[780px] rounded-shell shadow-modal';

/** A card's rise into place as it opens. */
const RISE = 'motion-safe:transition-[translate,scale] motion-safe:duration-400 motion-safe:ease-soft motion-safe:starting:translate-y-3.5 motion-safe:starting:scale-[0.985]';

const FRAME: Record<ModalVariant, string> = {
    centred: `admin-modal__frame--centred ${CARD} ${RISE} max-desktop:max-h-[94dvh] max-desktop:max-w-none max-desktop:self-end max-desktop:rounded-t-[1.75rem] max-desktop:rounded-b-none max-desktop:motion-safe:starting:translate-y-10`,
    filling: `admin-modal__frame--filling ${CARD} ${RISE} max-desktop:h-full max-desktop:max-h-none max-desktop:max-w-none max-desktop:rounded-none max-desktop:shadow-none`,
    full: 'admin-modal__frame--full h-full max-h-none max-w-none rounded-none',
    drawer: 'admin-modal__frame--drawer h-dvh w-[min(88vw,340px)] rounded-r-shell shadow-drawer motion-safe:transition-[translate] motion-safe:duration-450 motion-safe:ease-soft motion-safe:starting:-translate-x-full',
};

/**
 * The full frame's sides: what a part holds at most 62.5rem wide and centred, never nearer the
 * window's edge than 26 pixels, and below the breakpoint 18 pixels as on a card. A padding's
 * percentage is taken of the frame's width, so every part still spans the window.
 */
const COLUMN = 'px-[max(1.625rem,50%_-_31.25rem)] max-desktop:px-4.5';

/** One line of the dialog's title at each width, and of a prompt's, the height an aside is centred in. */
const DIALOG_LINE = 'h-[calc(var(--text-dialog-title)*var(--text-dialog-title--line-height))] max-desktop:h-[calc(var(--text-section-title)*var(--text-section-title--line-height))]';
const PROMPT_LINE = 'h-[calc(var(--text-section-title)*var(--text-section-title--line-height))]';

/**
 * Each part's room in a frame: a card's, set wide with the title at the dialog's size, and below
 * the breakpoint 18 pixels at the sides with the title a step down, so a phone's width goes to the
 * body; a prompt's title that step down at every width, as it asks one short question; the full
 * frame's, a card's room at the top and the foot with its sides the column's; and the drawer's,
 * which keeps its own: the title for a screen reader alone, and the body's foot clear of a phone's
 * home indicator.
 */
const CARD_ROOM = {
    title: 'px-6.5 pt-5.5 pb-3 max-desktop:px-4.5 max-desktop:pt-4.5 max-desktop:pb-2',
    type: 'text-dialog-title max-desktop:text-section-title',
    prompt: 'text-section-title',
    line: DIALOG_LINE,
    promptLine: PROMPT_LINE,
    body: 'px-6.5 pt-1 pb-5.5 max-desktop:px-4.5 max-desktop:pb-4.5',
    footer: 'px-6.5 py-3.5 max-desktop:px-4.5 max-desktop:py-3',
};

type Room = typeof CARD_ROOM;

const ROOM: Record<ModalVariant, Room> = {
    centred: CARD_ROOM,
    filling: CARD_ROOM,
    full: {
        title: `pt-5.5 pb-3 max-desktop:pt-4.5 max-desktop:pb-2 ${COLUMN}`,
        type: 'text-dialog-title max-desktop:text-section-title',
        prompt: 'text-section-title',
        line: DIALOG_LINE,
        promptLine: PROMPT_LINE,
        body: `pt-1 pb-5.5 max-desktop:pb-4.5 ${COLUMN}`,
        footer: `py-3.5 max-desktop:py-3 ${COLUMN}`,
    },
    drawer: { title: '', type: 'sr-only', prompt: 'sr-only', line: '', promptLine: '', body: 'px-4 pt-4 pb-[calc(1rem+env(safe-area-inset-bottom))]', footer: 'px-5 py-3' },
};

/**
 * The close control's place over the end of the title's row, and the room the row leaves it: the
 * control and 12 pixels between it and the title, both moving in with the sides below the
 * breakpoint. Its touch area is a layer of its own reaching 2 pixels past each side.
 */
const CLOSE = 'absolute top-4.5 right-5 size-10 after:absolute after:-inset-0.5 max-desktop:top-3 max-desktop:right-3';
const CLEAR_OF_CLOSE = 'pr-18 max-desktop:pr-16';

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
    /** Set beside the title at the end of its row, such as a close button; nothing unless given. */
    aside?: ReactNode;
    /** The frame's shape; the centred frame unless told. */
    variant?: ModalVariant;
    /** A confirm's frame, which asks one short question: its title a step down at every width. */
    prompt?: boolean;
    /** Whether the owner's act is in flight, when the close control is disabled; it is not unless told. */
    busy?: boolean;
    className?: string;
    children: ReactNode;
};

/**
 * The title on its own row, or with the aside after it on a shared one; either leaves the end of
 * the row clear where the close control is set over it. On a shared row the title's room is the
 * row's and the title keeps its type alone, and the aside sits in a box one of the title's lines
 * high at the row's top, so it is centred on the title's first line rather than on a box taller
 * than it, however many lines the title takes. A title breaks anywhere, so one long unbroken word
 * wraps inside its row instead of running under the control.
 */
function Heading({ title, aside, room, prompt, closable }: { title: string; aside: ReactNode; room: Room; prompt: boolean; closable: boolean }) {
    const type = prompt ? room.prompt : room.type;

    if (aside === undefined) {
        return <DialogPrimitive.Title className={cn('admin-modal__title shrink-0 wrap-anywhere', room.title, type, closable && CLEAR_OF_CLOSE)}>{title}</DialogPrimitive.Title>;
    }

    const line = prompt ? room.promptLine : room.line;

    return (
        <div className={cn('admin-modal__head flex shrink-0 items-start gap-2', room.title, closable && CLEAR_OF_CLOSE)}>
            <DialogPrimitive.Title className={cn('admin-modal__title min-w-0 flex-1 wrap-anywhere', type)}>{title}</DialogPrimitive.Title>
            <div className={cn('admin-modal__aside flex shrink-0 items-center', line)}>{aside}</div>
        </div>
    );
}

export function Modal({ open, title, onClose, footer, aside, variant = 'centred', prompt = false, busy = false, className, children }: Props) {
    const shell = useShell();
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

    const room = ROOM[variant];
    const closable = variant === 'centred' || variant === 'filling';

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
                    <DialogPrimitive.Overlay
                        className={cn(
                            'admin-modal__backdrop fixed inset-0 z-50 flex bg-scrim backdrop-blur-[3px] motion-safe:transition-opacity motion-safe:duration-300 motion-safe:ease-soft motion-safe:starting:opacity-0',
                            BACKDROP[variant],
                        )}
                        onPointerDown={down}
                        onPointerUp={up}
                    >
                        <DialogPrimitive.Content
                            aria-modal="true"
                            aria-describedby={undefined}
                            className={cn('admin-modal__frame relative flex w-full flex-col bg-card text-card-foreground outline-none', FRAME[variant], className)}
                            onOpenAutoFocus={() => {
                                opener.current = document.activeElement;
                            }}
                            onCloseAutoFocus={giveFocusBack}
                            onEscapeKeyDown={escape}
                            onInteractOutside={(event) => event.preventDefault()}
                        >
                            <Heading title={title} aside={aside} room={room} prompt={prompt} closable={closable} />
                            <div className={cn('admin-modal__body min-h-0 flex-1 overflow-y-auto text-body', room.body)}>
                                {variant === 'full' ? <div className="admin-modal__column @container">{children}</div> : children}
                            </div>
                            {footer !== undefined && <div className={cn('admin-modal__footer flex shrink-0 justify-end gap-2 border-t max-desktop:flex-wrap', room.footer)}>{footer}</div>}
                            {closable && (
                                <Button iconOnly icon={<Cross />} className={`admin-modal__close ${CLOSE}`} disabled={busy} onClick={onClose}>
                                    {shell.text('kit.modal.close')}
                                </Button>
                            )}
                        </DialogPrimitive.Content>
                    </DialogPrimitive.Overlay>
                </Enclosing.Provider>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
