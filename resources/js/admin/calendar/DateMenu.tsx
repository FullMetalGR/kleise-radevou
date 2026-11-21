import { type ComponentProps, type MouseEvent, type ReactNode, type Ref, useImperativeHandle, useRef, useState } from 'react';
import { DropdownMenu, type MenuItem } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { type CalendarKey, useCalendarWords } from './words';

/*
 * A day's date menu: the control behind a calendar day's empty space, the day's one tab stop. A
 * press opens the menu where it landed, Enter or Space under the control. The menu's title names
 * the day; its items are New booking where the viewer may add one, New closure where the Add menu
 * offers one, and Open day. A viewer offered neither of the first two gets no menu at all: the
 * press opens the day at once.
 *
 * Where it landed is a point, not an edge of the control moved by a distance: the list hangs under
 * the point or, where the window leaves no room there, over it, and never covers it, so the
 * release that ends the press finds no item under the pointer to pick.
 *
 * A mouse opens the menu as it goes down, as every menu here does. A finger or a pen going down
 * may be a scroll starting, so the menu waits for the click a tap ends in, which a scroll never
 * reaches; a press that finds the menu open closes it and its click opens nothing. A click that
 * no pointer made, a screen reader's or a script's, opens a closed menu whatever was pressed on
 * the control before it: a press with another button, one that ended off the control.
 *
 * It knows nothing of where it sits: the caller binds each handler to its day, and to its row
 * where it has one, and lays the control out, over a whole cell or as the cell's own face. The
 * control is named by its name, the title unless the caller says more; what it shows is the
 * caller's, hidden from a screen reader where the name already says it.
 *
 * An item that opens a dialog moves focus to the control first, so the dialog hands focus back to
 * it, a control still on the page, rather than to the item that is gone with the menu.
 */

export const NEW_BOOKING: CalendarKey = 'page.add_booking';

export const NEW_CLOSURE: CalendarKey = 'closure.title';

export const OPEN_DAY: CalendarKey = 'cells.open_day';

type Props = {
    /** The day, the menu's title. */
    title: string;
    /** The control's name, the title where not given. */
    name?: string;
    onBook?: () => void;
    onClosure?: () => void;
    onDay: () => void;
    /** Whether the day is the tenant's today. */
    current?: boolean;
    className?: string;
    children?: ReactNode;
};

/** What the menu holds of its control: something to measure, to hang the list from, and to hand focus back to. */
type Held = { focus: (options?: FocusOptions) => void; getBoundingClientRect: () => DOMRect; contextElement?: Element };

/**
 * A press's place in the control as the menu holds it: measured, it is the point pressed, kept by
 * its distance from the control's corner so it follows the control under a scroll; focused, it is
 * the control. None where the press names no place inside the control, as a click no pointer made.
 */
function spotOf(event: MouseEvent<HTMLButtonElement>): Held | null {
    const control = event.currentTarget;
    const box = control.getBoundingClientRect();
    const x = event.clientX - box.left;
    const y = event.clientY - box.top;

    if (x < 0 || x > box.width || y < 0 || y > box.height) {
        return null;
    }

    return {
        focus: (options) => control.focus(options),
        getBoundingClientRect: () => {
            const now = control.getBoundingClientRect();

            return new DOMRect(now.left + x, now.top + y, 0, 0);
        },
        contextElement: control,
    };
}

type ControlProps = Omit<ComponentProps<'button'>, 'ref'> & {
    ref?: Ref<Held | null>;
    /** Where the menu hangs from, the control itself where null. */
    spot: Held | null;
};

/**
 * The control's button. The menu hangs its list from what its trigger's ref answers and hands
 * focus back to the same: here the spot of a press where there is one, else the button, while
 * every attribute and every event stays the button's.
 */
function Control({ ref, spot, ...rest }: ControlProps) {
    const button = useRef<HTMLButtonElement>(null);

    useImperativeHandle<Held | null, Held | null>(ref, () => spot ?? button.current, [spot]);

    return <button ref={button} type="button" {...rest} />;
}

/**
 * What a press does to the menu, as the control's handlers. A mouse going down names its place and
 * leaves the opening to the menu. A finger or a pen going down is refused, and the click its tap
 * ends in opens the menu at its place, unless the press found the menu open and so closed it. A
 * click no pointer made opens a closed menu whatever was pressed before it.
 */
function usePress(open: boolean, setOpen: (open: boolean) => void, setSpot: (spot: Held | null) => void): Pick<ComponentProps<'button'>, 'onPointerDown' | 'onClick'> {
    // Whether the click ending the press now down opens the menu. Every press on the control says
    // so afresh as it goes down, and only the click a pointer makes reads it, which no pointer
    // makes without going down here first: a press that ended elsewhere leaves nothing behind.
    const opens = useRef(true);

    return {
        onPointerDown: (event) => {
            if (event.pointerType === 'mouse') {
                opens.current = false;
                setSpot(spotOf(event));

                return;
            }

            // A finger or a pen: refused, the press opens nothing, as the library would on any press.
            event.preventDefault();
            opens.current = !open;
        },
        onClick: (event) => {
            // A click counting no press is one no pointer made: a screen reader's, a script's.
            if ((event.detail === 0 || opens.current) && !open) {
                setSpot(spotOf(event));
                setOpen(true);
            }
        },
    };
}

const CONTROL = 'admin-calendar-date cursor-pointer text-left outline-none hover:bg-accent/40 focus-visible:inset-ring-3 focus-visible:inset-ring-ring/50';

export function DateMenu({ title, name = title, onBook, onClosure, onDay, current = false, className, children }: Props) {
    const words = useCalendarWords();
    const control = useRef<Held | null>(null);
    const [open, setOpen] = useState(false);
    const [spot, setSpot] = useState<Held | null>(null);
    const press = usePress(open, setOpen, setSpot);
    const marked = current ? 'date' : undefined;
    const face = (
        <>
            <span className="sr-only">{name}</span>
            {children}
        </>
    );

    if (onBook === undefined && onClosure === undefined) {
        return (
            <button type="button" className={cn(CONTROL, className)} aria-current={marked} onClick={onDay}>
                {face}
            </button>
        );
    }

    const handing = (show: () => void) => () => {
        control.current?.focus();
        show();
    };
    const items: MenuItem[] = [
        ...(onBook === undefined ? [] : [{ key: 'book', label: words.text(NEW_BOOKING), className: 'admin-calendar-date__book', onSelect: handing(onBook) }]),
        ...(onClosure === undefined ? [] : [{ key: 'closure', label: words.text(NEW_CLOSURE), className: 'admin-calendar-date__closure', onSelect: handing(onClosure) }]),
        { key: 'day', label: words.text(OPEN_DAY), className: 'admin-calendar-date__day', onSelect: onDay },
    ];

    return (
        <DropdownMenu
            label={title}
            title={title}
            items={items}
            open={open}
            onOpenChange={setOpen}
            trigger={
                <Control
                    ref={control}
                    spot={spot}
                    className={cn(CONTROL, className)}
                    aria-current={marked}
                    {...press}
                    onKeyDown={() => setSpot(null)}
                >
                    {face}
                </Control>
            }
        />
    );
}
