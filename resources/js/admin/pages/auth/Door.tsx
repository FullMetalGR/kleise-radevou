import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/*
 * The frame a business's sign-in pages share, outside the shell: a column on the page colour,
 * centred across and down, at most 420 pixels wide and the window's width less its gutters below
 * that. The mark stands at its head, where the page has one, then one form card holds the page's
 * heading and its form, with the card's own room around them. Without a mark nothing takes its
 * place and the card heads the column. The page keeps its own hook class on the landmark.
 */

type Props = {
    className: string;
    mark?: ReactNode;
    children: ReactNode;
};

export function Door({ className, mark, children }: Props) {
    return (
        <main className={cn('flex min-h-screen flex-col justify-center px-4 py-10', className)}>
            <div className="admin-door mx-auto flex w-full max-w-[26.25rem] flex-col gap-6">
                {mark !== undefined && mark !== null && <div className="admin-door__mark flex justify-center">{mark}</div>}
                <Card variant="form" className="admin-door__card flex flex-col gap-6">
                    {children}
                </Card>
            </div>
        </main>
    );
}
