import { statusLabel } from '@/components/ui/status-chip';
import { type ShellWords, useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import type { Badge } from './model';
import { type CalendarWords, useCalendarWords } from './words';

/*
 * The block badges, one register: small, bold, in capitals, before the title. Pending marks a
 * request awaiting approval, in the theme's attention colours as its chip is; Awaiting payment,
 * its chip's own word, marks an approved booking awaiting the customer's payment, in its
 * provisional chip's dashed primary over the card, so it reads on any member's hue. The legend
 * draws the same two beside what each marks. The words not a chip's are keys of the calendar
 * section. A block draws its badge where its body has the width for the badge whole and a start
 * of the title after it; in a narrower body the title has the line, the badge is its word for a
 * screen reader alone, and the block's outline tells the state as a chip's does. The marker of a
 * booking inside a closure is a pill in the same register, its own outline in the theme's
 * destructive red, first on the line and before a badge: worded where its block has the room for
 * the title beside it, an exclamation mark alone where it has not, and that mark in the legend
 * beside the words.
 */

/** A badge's word and what it marks, each read in the shell's words where it is a chip's and in the calendar's otherwise. */
type Words = (shell: ShellWords, calendar: CalendarWords) => string;

const BADGES: Record<Badge, { word: Words; meaning: Words; className: string }> = {
    awaiting_approval: { word: (_, calendar) => calendar.text('badges.pending'), meaning: (shell) => statusLabel('awaiting_approval', shell), className: 'admin-calendar-badge--approval border-attention-line bg-attention-soft-strong text-attention-ink' },
    awaiting_payment: { word: (shell) => statusLabel('awaiting_payment', shell), meaning: (_, calendar) => calendar.text('badges.payment'), className: 'admin-calendar-badge--payment border-dashed border-primary bg-card text-primary-deep' },
};

/** The badges in the order the legend lists them. */
export const BADGE_ORDER: readonly Badge[] = ['awaiting_approval', 'awaiting_payment'];

/**
 * The width of a block's body under which its badge is not drawn, one width whatever shares the
 * line. The widest badge takes some 154px of the line, in Greek, and some 176px with the marker's
 * mark before it, so from 195px on a badge is whole with at least some 18px of the title after
 * it, and under it the title has the line. The word stays the badge's text there, read by a
 * screen reader.
 */
const FITTED = '@max-[195px]:sr-only';

/** A badge as the legend draws it, at any width; fitted: as a block draws it, by the width of the block's body it is measured against. */
export function BlockBadge({ badge, fitted = false }: { badge: Badge; fitted?: boolean }) {
    const shell = useShell();
    const calendar = useCalendarWords();
    const { word, className } = BADGES[badge];

    return <span className={cn('admin-calendar-badge mr-1 inline-block rounded-full border px-1.5 align-[1px] text-xs leading-[14px] font-bold tracking-wide uppercase', className, fitted && FITTED)}>{word(shell, calendar)}</span>;
}

/** What a badge marks, as the legend says it. */
export function badgeMeaning(badge: Badge, shell: ShellWords, calendar: CalendarWords): string {
    return BADGES[badge].meaning(shell, calendar);
}

/** The marker's pill, drawn alike on a block and in the legend. */
const INSIDE = 'mr-1 inline-block rounded-full border border-destructive bg-card px-1.5 align-[1px] text-xs leading-[14px] font-bold tracking-wide text-destructive uppercase';

/**
 * The width of a block's body under which the marker is its mark alone, by what shares the
 * title's line: 241px where the marker is the line's one pill, which takes up to some 150px of
 * the line in words, in Greek, and 385px where a badge follows it, the two pills together taking
 * up to some 304px there. From either width on the title keeps at least some 90px of its line
 * beside the one pill and some 80px beside the two.
 */
const DRAWINGS = {
    alone: { mark: 'hidden @max-[241px]:inline', words: '@max-[241px]:sr-only' },
    beside: { mark: 'hidden @max-[385px]:inline', words: '@max-[385px]:sr-only' },
};

/**
 * The marker of a booking whose time a closure holds: one pill in two drawings, by the width of
 * the block's body it is measured against. Where the body is wide the pill is worded in full;
 * where it is narrow, Week's columns among them, the pill holds an exclamation mark alone, so the
 * title beside it always keeps room. The words stay the pill's name in the narrow drawing, read by
 * a screen reader and shown on hover. beside: a badge follows the marker on the same line.
 */
export function InsideMarker({ beside = false }: { beside?: boolean }) {
    const words = useCalendarWords().text('badges.closure');
    const drawing = DRAWINGS[beside ? 'beside' : 'alone'];

    return (
        <span className={cn('admin-calendar-inside', INSIDE)} title={words}>
            <span className={cn('admin-calendar-inside__mark', drawing.mark)} aria-hidden="true">
                !
            </span>
            <span className={cn('admin-calendar-inside__words', drawing.words)}>{words}</span>
        </span>
    );
}

/** The marker as the legend draws it, the narrow drawing at any width: the mark alone, the legend writing the words beside it. */
export function InsideMark() {
    return (
        <span className={cn('admin-calendar-legend__mark', INSIDE)} aria-hidden="true">
            !
        </span>
    );
}
