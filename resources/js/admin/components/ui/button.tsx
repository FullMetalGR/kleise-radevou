import type { ComponentProps, MouseEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/*
 * The button. Four variants: primary, secondary (the default), tertiary, and link, which carries
 * no chrome for use inside a sentence or a table cell. A destructive flag sits beside the variant
 * rather than being one. Three states: normal, disabled, and busy, which shows a spinner before
 * the label, sets aria-busy and disables the control; the busy label, required wherever busy is
 * written, replaces the label and is the text that states why it is disabled. The handler refuses
 * a press while busy or disabled as well, so nothing reaches it through a stale attribute.
 *
 * Every button is type="button" unless it says otherwise, so a button inside a form submits only
 * when it is written to.
 */

export type ButtonVariant = 'primary' | 'secondary' | 'tertiary' | 'link';

const VARIANTS: Record<ButtonVariant, string> = {
    primary: 'bg-primary text-primary-foreground hover:bg-primary/85 px-3 h-8',
    secondary: 'border-border bg-background hover:bg-muted px-3 h-8',
    tertiary: 'hover:bg-muted px-3 h-8',
    link: 'h-auto p-0 text-primary underline-offset-4 hover:underline',
};

const DESTRUCTIVE: Record<ButtonVariant, string> = {
    primary: 'bg-destructive text-white hover:bg-destructive/85',
    secondary: 'border-destructive/50 text-destructive hover:bg-destructive/10',
    tertiary: 'text-destructive hover:bg-destructive/10',
    link: 'text-destructive',
};

/**
 * A button that can be busy says why it is disabled while it is: the busy label comes with busy
 * wherever busy is written, by the rule that every disabled control states its reason in text.
 * A button that is never busy carries neither.
 */
type Busy = { busy?: false; busyLabel?: never } | { busy: boolean; busyLabel: string };

type Props = Omit<ComponentProps<'button'>, 'children'> &
    Busy & {
        variant?: ButtonVariant;
        destructive?: boolean;
        children: ReactNode;
    };

export function Button({ variant = 'secondary', destructive = false, busy = false, busyLabel, disabled = false, type = 'button', className, onClick, children, ...rest }: Props) {
    const refused = busy || disabled;

    const click = (event: MouseEvent<HTMLButtonElement>) => {
        if (refused) {
            event.preventDefault();

            return;
        }

        onClick?.(event);
    };

    return (
        <button
            type={type}
            className={cn(
                'admin-button inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-transparent text-sm font-medium whitespace-nowrap outline-none select-none focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-60',
                `admin-button--${variant}`,
                VARIANTS[variant],
                destructive && ['admin-button--destructive', DESTRUCTIVE[variant]],
                busy && 'admin-button--busy',
                className,
            )}
            disabled={refused}
            aria-busy={busy ? true : undefined}
            onClick={click}
            {...rest}
        >
            {busy && <span className="admin-button__spinner size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />}
            {busy ? busyLabel : children}
        </button>
    );
}
