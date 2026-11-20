import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { useFirstRow } from '@/shell/first-row';

/*
 * The header every screen inside the shell opens with, and the only place the page's main
 * heading is written. One row holds the title, then its count and its status beside it, then the
 * page's own buttons, pushed to the far side. The row wraps: a long title wraps inside itself and
 * the buttons drop under it, so nothing is pushed past the window. A breadcrumb, where the screen
 * is a detail page, sits above the row and a short lead line under it, kept to a readable
 * measure. The title is the page size from the wide breakpoint and the compact size below it.
 * The place keeps its own hook class in className.
 *
 * Inside the shell the row is the page's first row, and the shell's parts join it: the menu's
 * button before the title where the shell is compact, then on the far side the controls, the
 * page's own buttons and the shell's button last. That button is quiet where the page has a main
 * button of its own, which a page with buttons is taken to have unless it says otherwise.
 */

type Props = {
    title: ReactNode;
    count?: ReactNode;
    status?: ReactNode;
    lead?: ReactNode;
    breadcrumb?: ReactNode;
    actions?: ReactNode;
    /** Whether the shell's button steps back beside this page's; a page with buttons is taken to want it so. */
    quiet?: boolean;
    className?: string;
};

export function PageHeader({ title, count, status, lead, breadcrumb, actions, quiet, className }: Props) {
    const row = useFirstRow();
    const action = row.action === null ? null : row.action(quiet ?? actions !== undefined);
    const end = row.controls !== null || actions !== undefined || action !== null;

    return (
        <header className={cn('admin-page-header flex min-w-0 flex-col', className)}>
            {breadcrumb}
            <div className="admin-page-header__row admin-topbar flex min-w-0 flex-wrap items-center gap-x-4 gap-y-3 pt-5.5 pb-4.5">
                {row.start}
                <h1 className="admin-page-title min-w-0 text-page-title wrap-break-word max-wide:text-page-title-compact">{title}</h1>
                {count !== undefined && <span className="admin-page-header__count text-body text-muted-foreground">{count}</span>}
                {status}
                {end && (
                    <div className="admin-page-header__end ms-auto flex min-w-0 flex-wrap items-center justify-end gap-2">
                        {row.controls}
                        {actions !== undefined && <div className="admin-page-header__actions flex min-w-0 flex-wrap items-center gap-2">{actions}</div>}
                        {action}
                    </div>
                )}
            </div>
            {lead !== undefined && <p className="admin-page-header__lead -mt-2 mb-4.5 max-w-[78ch] text-[0.96875rem] leading-[1.55] wrap-break-word text-muted-foreground">{lead}</p>}
        </header>
    );
}
