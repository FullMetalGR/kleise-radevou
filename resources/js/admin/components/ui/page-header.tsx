import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/*
 * The header every screen inside the shell opens with, and the only place the page's main
 * heading is written. One row holds the title, then its count and its status beside it, then the
 * page's own buttons, pushed to the far side. The row wraps: a long title wraps inside itself and
 * the buttons drop under it, so nothing is pushed past the window. A breadcrumb, where the screen
 * is a detail page, sits above the row and a short lead line under it, kept to a readable
 * measure. The title is the page size from the wide breakpoint and the compact size below it.
 * The place keeps its own hook class in className.
 */

type Props = {
    title: ReactNode;
    count?: ReactNode;
    status?: ReactNode;
    lead?: ReactNode;
    breadcrumb?: ReactNode;
    actions?: ReactNode;
    className?: string;
};

export function PageHeader({ title, count, status, lead, breadcrumb, actions, className }: Props) {
    return (
        <header className={cn('admin-page-header flex min-w-0 flex-col', className)}>
            {breadcrumb}
            <div className="admin-page-header__row flex min-w-0 flex-wrap items-center gap-x-4 gap-y-3 pt-5.5 pb-4.5">
                <h1 className="admin-page-title min-w-0 text-page-title wrap-break-word max-wide:text-page-title-compact">{title}</h1>
                {count !== undefined && <span className="admin-page-header__count text-body text-muted-foreground">{count}</span>}
                {status}
                {actions !== undefined && <div className="admin-page-header__actions ms-auto flex min-w-0 flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {lead !== undefined && <p className="admin-page-header__lead -mt-2 mb-4.5 max-w-[78ch] text-[0.96875rem] leading-[1.55] wrap-break-word text-muted-foreground">{lead}</p>}
        </header>
    );
}
