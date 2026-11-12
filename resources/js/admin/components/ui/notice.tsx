import { useState, type ReactNode } from 'react';
import { useShell } from '@/lib/strings';
import { cn } from '@/lib/utils';

/*
 * The notice: info, warning, error and success. An error notice takes the alert role; the rest
 * take status. A dismissible notice removes itself on its control and tells its owner, which may
 * forget whatever put it there. The control is named by the kit's words, from the strings the
 * notice is given.
 */

export type NoticeKind = 'info' | 'warning' | 'error' | 'success';

const TONE: Record<NoticeKind, string> = {
    info: 'border-border bg-muted',
    warning: 'border-amber-500/50 bg-amber-500/10',
    error: 'border-destructive/50 bg-destructive/10 text-destructive',
    success: 'border-emerald-600/50 bg-emerald-600/10',
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

    return (
        <div role={kind === 'error' ? 'alert' : 'status'} className={cn('admin-notice flex items-start gap-3 rounded-lg border px-3 py-2 text-sm', `admin-notice--${kind}`, TONE[kind], className)}>
            <div className="admin-notice__body flex-1">{children}</div>
            {dismissible && (
                <button type="button" className="admin-notice__dismiss text-muted-foreground hover:text-foreground" aria-label={shell.text('kit.notice.dismiss')} onClick={dismiss}>
                    x
                </button>
            )}
        </div>
    );
}
