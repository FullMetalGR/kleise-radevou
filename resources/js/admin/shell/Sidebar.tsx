import { type ReactNode, useState } from 'react';
import { Chevron } from '@/components/glyphs';
import { Initials } from '@/components/initials';
import { Tooltip } from '@/components/ui/tooltip';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import type { NavEntry } from '../types';
import { readCollapsed, writeCollapsed } from './collapsed';
import { Rail } from './Rail';

/*
 * The sidebar the shell stands beside the page from the wide breakpoint: a rounded card floating
 * at the left, held in view as the page scrolls and scrolling on its own where the menu runs
 * longer than the window. At its head the brand, then a chip with the business's name, then the
 * menu in its groups, and at its foot the button that folds it to a strip of icons and back.
 * Folded, the button wears its words as a tooltip; open, it shows them and holds the tooltip shut,
 * so it is the same element in both states and a press from the keyboard leaves the focus on it.
 * The aside keeps the class a screen with no aside is known by, beside its own.
 *
 * Its column is 276px wide, 252px where the shell itself is narrower than the narrow-shell token,
 * and 104px folded, the change of width eased where motion is welcome. The column holds the
 * card's gutter at the left, so the card stands off the window's edge.
 *
 * The choice to fold is remembered on the device. Where the browser refuses to store it, the
 * sidebar starts open and the button still folds and opens it for the visit.
 */

type Props = {
    nav: NavEntry[];
    /** The brand at the head, given whether the sidebar is folded, where it shows the mark alone. */
    brand: (collapsed: boolean) => ReactNode;
    /** The business's name for its chip; none for the console, which has no business. */
    business: string | null;
};

function localStorageOf(): Storage {
    return window.localStorage;
}

export function Sidebar({ nav, brand, business }: Props) {
    const shell = useShell();
    const [collapsed, setCollapsed] = useState(() => readCollapsed(localStorageOf));

    const toggle = () => {
        const next = !collapsed;

        setCollapsed(next);
        writeCollapsed(next, localStorageOf);
    };

    const label = shell.text(collapsed ? 'nav.expand' : 'nav.collapse');

    const button = (
        <button
            type="button"
            className={cn(
                'admin-sidebar-toggle focus-ring flex min-h-11 w-full items-center gap-3 rounded-full py-1 ps-1 pe-3 text-sm font-semibold text-muted-foreground hover:bg-muted hover:text-foreground',
                collapsed && 'justify-center pe-1',
            )}
            aria-expanded={!collapsed}
            onClick={toggle}
        >
            <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-full motion-safe:transition-transform motion-safe:duration-400 motion-safe:ease-soft', collapsed && 'rotate-180')} aria-hidden="true">
                <Chevron to="previous" />
            </span>
            <span className={cn('min-w-0 truncate', collapsed && 'sr-only')}>{label}</span>
        </button>
    );

    return (
        <aside className={cn('admin-sidebar admin-aside shrink-0 ps-4 motion-safe:transition-[width] motion-safe:duration-400 motion-safe:ease-soft', collapsed ? 'w-26' : 'w-69 @max-shell-narrow:w-63')}>
            <div
                className={cn(
                    'admin-sidebar__card sticky top-4 my-4 flex h-[calc(100vh-2rem)] flex-col gap-3.5 overflow-x-hidden overflow-y-auto rounded-shell bg-card pt-4.5 pb-3.5 shadow-card [scrollbar-width:thin]',
                    collapsed ? 'px-2.5' : 'px-3.5',
                )}
            >
                <div className={cn('admin-sidebar__head flex min-h-11 shrink-0 items-center', collapsed ? 'justify-center' : 'px-1.5')}>{brand(collapsed)}</div>
                {business !== null && <BusinessChip name={business} collapsed={collapsed} />}
                <Rail nav={nav} collapsed={collapsed} />
                <div className="admin-sidebar__foot mt-auto shrink-0 border-t pt-2.5">
                    <Tooltip label={label} disabled={!collapsed}>
                        {button}
                    </Tooltip>
                </div>
            </div>
        </aside>
    );
}

/**
 * The business's chip: its initials in the spark's circle and its name beside them, cut with an
 * ellipsis where it runs long and whole in the chip's title. Folded, the circle alone shows and
 * the name stays for a screen reader.
 */
export function BusinessChip({ name, collapsed = false }: { name: string; collapsed?: boolean }) {
    return (
        <div className={cn('admin-business flex shrink-0 items-center gap-2.5 rounded-full bg-muted text-body font-semibold', collapsed ? 'justify-center p-1.5' : 'py-2 ps-2 pe-3')} title={name}>
            <Initials name={name} size="row" className="bg-spark text-spark-foreground" />
            <span className={cn('admin-business__name min-w-0 truncate', collapsed && 'sr-only')}>{name}</span>
        </div>
    );
}
