import { statusLabel } from '@/components/ui/status-chip';
import { type ShellWords, useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import type { Badge } from './model';
import { type CalendarWords, useCalendarWords } from './words';

/*
 * The block badges, one register: small, bold, in capitals, before the title. Pending marks a
 * request awaiting approval, amber as its attention chip is; Awaiting payment, its chip's own
 * word, marks an approved booking awaiting the customer's payment, in its provisional chip's
 * dashed sky over the card, so it reads on any member's hue. The legend draws the same two beside
 * what each marks. The words not a chip's are keys of the calendar section.
 */

/** A badge's word and what it marks, each read in the shell's words where it is a chip's and in the calendar's otherwise. */
type Words = (shell: ShellWords, calendar: CalendarWords) => string;

const BADGES: Record<Badge, { word: Words; meaning: Words; className: string }> = {
    awaiting_approval: { word: (_, calendar) => calendar.text('badges.pending'), meaning: (shell) => statusLabel('awaiting_approval', shell), className: 'admin-calendar-badge--approval border-amber-500 bg-amber-400 text-black' },
    awaiting_payment: { word: (shell) => statusLabel('awaiting_payment', shell), meaning: (_, calendar) => calendar.text('badges.payment'), className: 'admin-calendar-badge--payment border-dashed border-sky-600 bg-card text-sky-700 dark:text-sky-300' },
};

/** The badges in the order the legend lists them. */
export const BADGE_ORDER: readonly Badge[] = ['awaiting_approval', 'awaiting_payment'];

export function BlockBadge({ badge }: { badge: Badge }) {
    const shell = useShell();
    const calendar = useCalendarWords();
    const { word, className } = BADGES[badge];

    return <span className={cn('admin-calendar-badge mr-1 inline-block rounded-full border px-1.5 align-[1px] text-[10px] leading-[14px] font-bold tracking-wide uppercase', className)}>{word(shell, calendar)}</span>;
}

/** What a badge marks, as the legend says it. */
export function badgeMeaning(badge: Badge, shell: ShellWords, calendar: CalendarWords): string {
    return BADGES[badge].meaning(shell, calendar);
}
