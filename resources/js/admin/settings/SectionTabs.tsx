import { type MouseEvent, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { currentSection } from './spy';

/*
 * The row of links over Settings: one link to each section's heading, named by it, in the page's
 * order, inside a navigation landmark of its own name. Every section stays on the one page and in
 * the one form; the row only moves the reader. It sticks near the top of the screen as a glass
 * pill and scrolls sideways inside itself when its links run past it, so the page never scrolls
 * sideways.
 *
 * The section whose card has crossed a line 140px from the top is the current one, filled in the
 * ink and marked with aria-current. The line is read once a frame however many scroll events
 * arrive in it, and the current link is kept in view inside the row. A pressed link is current at
 * once and through the frame its jump scrolls, since the page can end before the last sections'
 * cards reach the line; the reader's next scroll is read from the line again.
 *
 * A press scrolls its heading into view and leaves the address alone. A hash in the address would
 * add an entry to the history, and going back to the entry before it remounts the page and drops
 * every unsaved edit. With no hash the browser moves nothing but the page, so the press hands the
 * heading the keyboard's focus itself, as a link's target takes it: the next Tab goes on inside
 * the section and a screen reader says where the reader landed. A press with a key held, or with
 * another button, is left to the browser.
 *
 * Each link is drawn 38px high and answers a touch 44px high, on a layer of its own reaching 3px
 * past it: half the 6px between two links, so no two layers overlap, and inside the pill's 8px of
 * padding, so the row clips none. A link the keyboard reaches half out of the row is brought in
 * whole with the same 8px to spare, which its focus outline fits in; the row's scroll padding
 * says the same to a browser that scrolls to a focused link by itself.
 */

/** How far from the top of the screen a section's card must come to be the current one. */
const LINE = 140;

/** The room kept between a link brought into view and the row's edge, the pill's own padding. */
const ROOM = 8;

export interface SectionLink {
    id: string;
    title: string;
}

type Props = {
    label: string;
    sections: SectionLink[];
};

/** Each section's card and where its top sits, for the sections the page draws. */
function topsOf(ids: string[]) {
    return ids.flatMap((id) => {
        const card = document.getElementById(`section-${id}`)?.closest('section');

        return card ? [{ id, top: card.getBoundingClientRect().top }] : [];
    });
}

/** Scroll the row just far enough that the link sits inside it with the room to spare. */
function keepInView(row: HTMLElement, link: HTMLElement): void {
    const edges = row.getBoundingClientRect();
    const box = link.getBoundingClientRect();

    if (box.left < edges.left + ROOM) {
        row.scrollLeft = Math.max(0, row.scrollLeft - (edges.left + ROOM - box.left));
    } else if (box.right > edges.right - ROOM) {
        row.scrollLeft = row.scrollLeft + (box.right - edges.right + ROOM);
    }
}

/**
 * Hand a heading the keyboard's focus where it stands, scrolling nothing. A heading is no stop of
 * the Tab key: it is made reachable for this alone and is plain again once the focus moves on.
 * Switching to another window blurs the heading too but leaves it the page's focused element, and
 * a heading made plain then would lose the focus the reader comes back to; so it stays reachable
 * until the focus moves on inside the page.
 */
function focusOn(heading: HTMLElement): void {
    const moved = () => {
        if (document.activeElement !== heading) {
            heading.removeAttribute('tabindex');
            heading.removeEventListener('blur', moved);
        }
    };

    heading.tabIndex = -1;
    heading.addEventListener('blur', moved);
    heading.focus({ preventScroll: true });
}

/** Scroll a plain press's heading into view and focus it, and say whether the press was taken. */
function jump(event: MouseEvent<HTMLAnchorElement>, id: string): boolean {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
    }

    event.preventDefault();
    const heading = document.getElementById(`section-${id}`);

    if (heading) {
        heading.scrollIntoView({ block: 'start' });
        focusOn(heading);
    }

    return true;
}

export function SectionTabs({ label, sections }: Props) {
    const [current, setCurrent] = useState<string | null>(sections[0]?.id ?? null);
    const row = useRef<HTMLUListElement>(null);
    const pressed = useRef<string | null>(null);
    const ids = sections.map((section) => section.id).join(' ');

    useEffect(() => {
        const list = ids.split(' ');
        let frame: number | null = null;
        const read = () => {
            const held = pressed.current;
            frame = null;
            pressed.current = null;
            setCurrent(held ?? currentSection(topsOf(list), LINE));
        };
        const ask = () => {
            frame ??= requestAnimationFrame(read);
        };

        ask();
        window.addEventListener('scroll', ask, { passive: true });
        window.addEventListener('resize', ask);

        return () => {
            window.removeEventListener('scroll', ask);
            window.removeEventListener('resize', ask);

            if (frame !== null) {
                cancelAnimationFrame(frame);
            }
        };
    }, [ids]);

    useEffect(() => {
        const link = row.current?.querySelector<HTMLElement>('a[aria-current="true"]');

        if (row.current && link) {
            keepInView(row.current, link);
        }
    }, [current]);

    return (
        <nav aria-label={label} className="admin-settings-tabs sticky top-3 z-8">
            <ul ref={row} className="flex scroll-px-2 gap-1.5 overflow-x-auto rounded-full bg-background/92 p-2 backdrop-blur-sm [scrollbar-width:none] max-wide:rounded-inner">
                {sections.map((section) => {
                    const chosen = section.id === current;

                    return (
                        <li key={section.id} className="flex flex-none">
                            <a
                                href={`#section-${section.id}`}
                                aria-current={chosen ? 'true' : undefined}
                                className={cn(
                                    'focus-ring relative inline-flex h-9.5 flex-none items-center rounded-full px-4 text-body-sm font-semibold whitespace-nowrap after:absolute after:-inset-0.75 motion-safe:transition-colors',
                                    chosen ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-card hover:text-foreground',
                                )}
                                onFocus={(event) => {
                                    // Only a focus that draws the outline: scrolling the row under
                                    // a pointer mid-press would move the link out from under it.
                                    if (row.current && event.currentTarget.matches(':focus-visible')) {
                                        keepInView(row.current, event.currentTarget);
                                    }
                                }}
                                onClick={(event) => {
                                    if (jump(event, section.id)) {
                                        pressed.current = section.id;
                                        setCurrent(section.id);
                                    }
                                }}
                            >
                                {section.title}
                            </a>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
