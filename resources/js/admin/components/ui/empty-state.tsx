import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/*
 * What an empty list shows in place of its rows: a small icon, the sentence, and under it the
 * action where the list has one, centred in the room the rows would take and set in the quieter
 * ink. The icon is decoration, drawn at 28px and hidden from assistive technology; the sentence
 * says what is empty. A sentence with a heading of its own passes both as paragraphs.
 *
 * It adds no hook class: each place keeps its own in className, beside its layout classes.
 */

type Props = { icon?: ReactNode; action?: ReactNode; className?: string; children: ReactNode };

export function EmptyState({ icon, action, className, children }: Props) {
    return (
        <div className={cn('flex flex-col items-center gap-3 px-5 py-14 text-center text-body font-semibold text-muted-foreground', className)}>
            {icon !== undefined && (
                <span aria-hidden="true" className="inline-flex *:size-7">
                    {icon}
                </span>
            )}
            <div className="flex flex-col gap-1">{children}</div>
            {action}
        </div>
    );
}
