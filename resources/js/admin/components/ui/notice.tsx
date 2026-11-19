import { useState, type ComponentType, type ReactNode } from 'react';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';
import { Alert, Cross, Info, Success, Warning } from '../glyphs';
import { Button } from './button';

/*
 * The notice: info, warning, error and success. An error notice takes the alert role; the rest
 * take status. Each kind starts with its icon, so the kind never rests on its colour, and sits on
 * the soft ground of its family, its words in that family's ink: the tint for info, the amber, the
 * red and the green, each with a line of its own round the edge. A dismissible notice removes
 * itself on its control, a small round icon button, and tells its owner, which may forget
 * whatever put it there. The control is named by the kit's words, from the strings the notice is
 * given.
 */

export type NoticeKind = 'info' | 'warning' | 'error' | 'success';

const KINDS: Record<NoticeKind, { readonly tone: string; readonly icon: ComponentType }> = {
    info: { tone: 'border-border bg-muted text-foreground', icon: Info },
    warning: { tone: 'border-attention-line bg-attention-soft text-attention-ink', icon: Warning },
    error: { tone: 'border-destructive/40 bg-destructive-soft text-destructive', icon: Alert },
    success: { tone: 'border-positive/40 bg-positive-soft text-positive-ink', icon: Success },
};

type Props = {
    kind: NoticeKind;
    dismissible?: boolean;
    onDismiss?: () => void;
    className?: string;
    children: ReactNode;
};

export function Notice({ kind, dismissible = false, onDismiss, className, children }: Props) {
    const shell = useShell();
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) {
        return null;
    }

    const dismiss = () => {
        setDismissed(true);
        onDismiss?.();
    };

    const { tone, icon: Icon } = KINDS[kind];
    const name = shell.text('kit.notice.dismiss');

    return (
        <div role={kind === 'error' ? 'alert' : 'status'} className={cn('admin-notice flex items-start gap-2.5 rounded-notice border px-4 py-3 text-body-sm font-semibold', `admin-notice--${kind}`, tone, className)}>
            <span className="admin-notice__icon mt-0.5 inline-flex shrink-0">
                <Icon />
            </span>
            <div className="admin-notice__body min-w-0 flex-1 wrap-anywhere">{children}</div>
            {dismissible && (
                <Button variant="tertiary" size="small" iconOnly icon={<Cross />} className="admin-notice__dismiss -my-1.5 -mr-2 text-current" aria-label={name} onClick={dismiss}>
                    {name}
                </Button>
            )}
        </div>
    );
}
