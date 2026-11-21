import { Dialog as DialogPrimitive } from 'radix-ui';
import { type KeyboardEvent, type ReactNode, type RefObject, useEffect, useLayoutEffect, useRef } from 'react';
import { Cross } from '@/components/glyphs';
import { Button } from '@/components/ui/button';
import { Tab, TabList, TabPanel, Tabs } from '@/components/ui/tabs';
import type { TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { Answers } from './Answers';
import type { HelpSession, Tab as HelpTab } from './session';
import { TicketsTab } from './TicketsTab';
import type { HelpBubble } from './types';
import type { HelpWords } from './words';

/*
 * The panel the bubble opens. At the desktop width it is a rounded card floating over the bottom
 * corner above the bubble and is not a dialog the page waits on: the page stays usable around it,
 * and it closes on a press outside it, on focus leaving it, on the bubble, on Close and on Escape.
 * Tab walks out of it at either end instead of round inside it. The bubble and the toasts are not
 * outside it: a press on a toast, or focus on one, leaves it open. A press counts when its click
 * arrives. A close that came from outside leaves focus where the press or the Tab put it; every
 * other close hands focus back to the bubble. Below that width it fills the screen as a dialog,
 * square-cornered, focus kept inside and the page behind not scrolling.
 *
 * Its head holds its heading, the bubble's name, and Close, the round icon button of the dialogs;
 * under the head, two tabs, the answers and the tickets, in a row the heading's words name, or
 * the answers alone for a viewer with no tickets. Each view marks the control focus goes to when
 * it is shown, the search, the button that opens a ticket, the subject or the way back to the
 * list, and focus moves there when the panel opens and each time the tickets' view changes.
 * Moving focus never scrolls: each view of the tickets starts at its top, and a thread then brings
 * its reply box into view itself.
 *
 * Neither the panel nor its backdrop is printed.
 */

type Props = { session: HelpSession; words: HelpWords; help: HelpBubble; desktop: boolean; clock: TenantClock; bubble: RefObject<HTMLElement | null> };

const FOCUS = '[data-help-focus]';

/** Where the toasts stand: over the panel's layer, and never counted as outside it. */
const TOASTS = '.admin-toaster';

function inToasts(target: EventTarget | null): boolean {
    return target instanceof Element && target.closest(TOASTS) !== null;
}

const BODY = 'admin-help-body min-h-0 flex-1 overflow-y-auto p-4 outline-none';

/**
 * Close, the round icon button the dialogs close by, 40 pixels across, its touch area a layer of
 * its own reaching 2 pixels past each side; its name is kept for a screen reader.
 */
const CLOSE = 'admin-help-close relative size-10 after:absolute after:-inset-0.5';

/** Each tab shares the row's width with the other. */
const TAB = 'admin-help-tab relative flex-1';

/**
 * The dot on the Tickets tab while a reply is unread: the bubble's own, the red inside a line in
 * the page's colour. The tab under it is the row's tint until it is chosen and the ink after, and
 * in dark mode the ink is nearly as light as the red, so there the dot is seen by its line.
 */
const DOT = 'admin-help-tab__dot ml-1 inline-block size-3 rounded-full border-2 border-background bg-destructive align-middle';

/**
 * The control focus goes to in the view shown: inside the tab now chosen, since both tabs stay in
 * the page, so each keeps its view and what was typed in it, the one not chosen hidden and so out
 * of the Tab order.
 */
function focusIn(frame: HTMLElement | null): HTMLElement | null {
    const scope = frame?.querySelector('[role="tabpanel"][data-state="active"]') ?? frame;

    return scope?.querySelector<HTMLElement>(FOCUS) ?? null;
}

function tabOf(value: string): HelpTab {
    return value === 'tickets' ? 'tickets' : 'answers';
}

function Views({ session, words, help, clock }: Pick<Props, 'session' | 'words' | 'help' | 'clock'>) {
    const shell = useShell();
    const tickets = useRef<HTMLDivElement>(null);
    const view = session.tickets.view;
    const shown = view.kind === 'thread' ? view.uuid : view.kind;
    const answers = <Answers session={session} words={words} tickets={help.tickets} />;

    useLayoutEffect(() => {
        if (tickets.current !== null) {
            tickets.current.scrollTop = 0;
        }
    }, [shown]);

    if (!help.tickets) {
        return <div className="admin-help-body min-h-0 flex-1 overflow-y-auto p-4">{answers}</div>;
    }

    return (
        <Tabs value={session.tab} onValueChange={(value) => session.chooseTab(tabOf(value))} className="flex min-h-0 flex-1 flex-col">
            <TabList label={shell.text('help.name')} className="admin-help-tabs mx-4 mt-3 flex shrink-0">
                <Tab value="answers" className={TAB}>
                    {words.text('tabs.answers')}
                </Tab>
                <Tab value="tickets" className={TAB} aria-label={help.unread ? words.text('tabs.tickets_unread') : undefined}>
                    {words.text('tabs.tickets')}
                    {help.unread && <span className={DOT} aria-hidden="true" />}
                </Tab>
            </TabList>
            <TabPanel value="answers" forceMount className={BODY}>
                {answers}
            </TabPanel>
            <TabPanel ref={tickets} value="tickets" forceMount className={BODY}>
                <TicketsTab tickets={session.tickets} words={words} clock={clock} />
            </TabPanel>
        </Tabs>
    );
}

function Frame({ desktop, children }: { desktop: boolean; children: ReactNode }) {
    if (desktop) {
        return <DialogPrimitive.Portal>{children}</DialogPrimitive.Portal>;
    }

    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="admin-help-backdrop fixed inset-0 z-40 bg-background print:hidden" />
            {children}
        </DialogPrimitive.Portal>
    );
}

export function Panel({ session, words, help, desktop, clock, bubble }: Props) {
    const shell = useShell();
    const frame = useRef<HTMLDivElement>(null);
    /** Whether the close under way came from a press or a focus outside the panel. */
    const left = useRef(false);
    /** Whether the press under way began on a toast. */
    const onToast = useRef(false);
    /** Whether the primary-button press under way began away from the panel, the bubble and the toasts. */
    const away = useRef(false);
    /** Whether a click no pointer made has come since the last press began. */
    const keyed = useRef(false);
    const { open, close } = session;
    const moved = session.tickets.moved;
    const onTickets = session.tab === 'tickets';

    // Only a change of view moves focus, and one behind the answers leaves it where it is.
    useEffect(() => {
        if (onTickets) {
            focusIn(frame.current)?.focus({ preventScroll: true });
        }
    }, [moved]);

    /*
     * A press outside is counted when its click arrives, and a toast pressed away is out of the
     * page by then: where a press began is read as it begins. The dialog lets go a press whose
     * click a control of the page keeps from the document, and hears no focus for the length of
     * it, so the click of a press begun away closes the panel here, before the page handles it.
     * The panel's own capture handler clears the mark for a press begun inside it. Below the
     * desktop width nothing outside closes the panel, so nothing is listened for.
     *
     * A press that never gets its click, one that drags the page's scrollbar say, goes on waiting
     * for one, here and in the dialog. Only a pointer's click is that click, and it carries the
     * count of its presses: a click with none is a key's on a button or a form sent by Enter, and
     * it ends the wait without closing the panel, the dialog's count of the press spared with
     * it. A press the browser cancels gets no click, and is forgotten.
     */
    useEffect(() => {
        away.current = false;

        if (!open || !desktop) {
            return;
        }

        const began = (event: PointerEvent) => {
            const target = event.target;

            keyed.current = false;
            onToast.current = inToasts(target);
            away.current = event.button === 0 && !onToast.current && !(target instanceof Node && bubble.current !== null && bubble.current.contains(target));
        };
        const cancelled = () => {
            away.current = false;
        };
        const clicked = (event: MouseEvent) => {
            if (event.detail === 0) {
                keyed.current = true;
            } else if (away.current) {
                left.current = true;
                close();
            }

            away.current = false;
        };

        document.addEventListener('pointerdown', began, true);
        document.addEventListener('pointercancel', cancelled, true);
        document.addEventListener('click', clicked, true);

        return () => {
            document.removeEventListener('pointerdown', began, true);
            document.removeEventListener('pointercancel', cancelled, true);
            document.removeEventListener('click', clicked, true);
        };
    }, [open, desktop, close, bubble]);

    const focusFirst = (event: Event) => {
        const first = focusIn(frame.current);
        left.current = false;

        if (first !== null) {
            event.preventDefault();
            first.focus({ preventScroll: true });
        }
    };

    /**
     * A press or a focus outside the panel. Below the desktop width nothing outside closes it,
     * and a toast never does, nor a press whose wait a key's click ended: spared says so.
     * Anywhere else closes it, the bubble included, which the dialog closes by its own press;
     * only a close begun elsewhere than the bubble counts as one from outside.
     */
    const outside = (event: Event, spared: boolean) => {
        const target = event.target;

        if (!desktop || spared) {
            event.preventDefault();

            return;
        }

        if (!(target instanceof Node) || bubble.current === null || !bubble.current.contains(target)) {
            left.current = true;
        }
    };

    /** Focus once the panel has closed: back on the bubble, unless the close came from outside. */
    const focusAfter = (event: Event) => {
        event.preventDefault();

        if (!left.current) {
            bubble.current?.focus();
        }

        left.current = false;
    };

    /**
     * At the desktop width Tab is kept from the dialog's own handler, which would carry focus
     * from the panel's last control round to its first: the browser takes it on out of the panel.
     */
    const tabOut = (event: KeyboardEvent) => {
        if (desktop && event.key === 'Tab') {
            event.stopPropagation();
        }
    };

    return (
        <Frame desktop={desktop}>
            <DialogPrimitive.Content
                ref={frame}
                aria-modal={desktop ? undefined : 'true'}
                aria-describedby={undefined}
                className={cn(
                    'admin-help-panel z-40 flex flex-col bg-card text-card-foreground outline-none print:hidden',
                    desktop ? 'fixed right-4 bottom-20 h-[min(600px,calc(100vh-6rem))] w-[400px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-tile-compact shadow-modal' : 'fixed inset-0',
                )}
                onOpenAutoFocus={focusFirst}
                onCloseAutoFocus={focusAfter}
                onEscapeKeyDown={(event) => {
                    if (event.isComposing) {
                        event.preventDefault();
                    }
                }}
                onPointerDownCapture={() => {
                    away.current = false;
                }}
                onPointerDownOutside={(event) => outside(event, onToast.current || keyed.current)}
                onFocusOutside={(event) => outside(event, inToasts(event.target))}
            >
                <div className="contents" onKeyDown={tabOut}>
                    <div className="admin-help-head flex shrink-0 items-center gap-3 px-4 pt-4">
                        <DialogPrimitive.Title className="admin-help-title min-w-0 flex-1 text-card-title break-words">{shell.text('help.name')}</DialogPrimitive.Title>
                        <DialogPrimitive.Close asChild>
                            <Button iconOnly icon={<Cross />} className={CLOSE}>
                                {words.text('close')}
                            </Button>
                        </DialogPrimitive.Close>
                    </div>
                    <Views session={session} words={words} help={help} clock={clock} />
                </div>
            </DialogPrimitive.Content>
        </Frame>
    );
}
