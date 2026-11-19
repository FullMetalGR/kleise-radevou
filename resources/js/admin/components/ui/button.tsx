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
 * Every button but the link is a pill in bold type, 44px high, 36 small and 48 large. An icon sits
 * before the label, hidden from assistive technology; an icon-only button is round, its label
 * kept for a screen reader alone. The primary carries the theme colour and a soft glow of it, the
 * secondary sits on the card with a line inside its edge, the tertiary has no line. Every one
 * shows the focus ring on keyboard focus, darkens on hover and shrinks a little while pressed,
 * the movement only where the person has not asked for less of it.
 *
 * A button keeps to one line. One whose label is wider than a phone's row in a language of longer
 * words is told to wrap: below the admin's breakpoint it holds to its row's width and the label
 * wraps, the lines centred, the button growing with them and never under its own height. A link
 * keeps to its line and an icon-only button shows no label, so neither takes anything from it.
 *
 * Every button is type="button" unless it says otherwise, so a button inside a form submits only
 * when it is written to.
 */

export type ButtonVariant = 'primary' | 'secondary' | 'tertiary' | 'link';

type ButtonSize = 'small' | 'default' | 'large';

const VARIANTS: Record<ButtonVariant, string> = {
    primary: 'bg-primary text-primary-foreground shadow-glow enabled:hover:bg-primary-deep enabled:hover:shadow-glow-hover disabled:shadow-none',
    secondary: 'bg-card text-foreground inset-ring-[1.5px] inset-ring-border enabled:hover:bg-muted enabled:hover:inset-ring-ink-tertiary',
    tertiary: 'text-foreground enabled:hover:bg-muted',
    link: 'h-auto rounded-md p-0 text-primary-deep underline-offset-4 enabled:hover:underline',
};

/*
 * The destructive forms: the primary solid red, darkened by brightness on hover with no glow; the
 * secondary the tinted red, its line the red at 40 percent, the full red and thicker on hover;
 * the tertiary and the link the red ink.
 */
const DESTRUCTIVE: Record<ButtonVariant, string> = {
    primary: 'bg-destructive text-destructive-foreground shadow-none enabled:hover:bg-destructive enabled:hover:brightness-92 enabled:hover:shadow-none',
    secondary: 'bg-destructive-soft text-destructive inset-ring-destructive/40 enabled:hover:bg-destructive-soft enabled:hover:inset-ring-2 enabled:hover:inset-ring-destructive',
    tertiary: 'text-destructive enabled:hover:bg-destructive-soft',
    link: 'text-destructive',
};

/** Each height with its padding and type; the small one sets its label a little smaller. */
const SIZES: Record<ButtonSize, string> = {
    small: 'admin-button--small h-9 px-3.5 text-sm',
    default: 'h-11 px-4.5',
    large: 'admin-button--large h-12 px-6 text-body',
};

/** An icon-only button is as wide as it is high at each height. */
const ICON_ONLY_SIZES: Record<ButtonSize, string> = {
    small: 'admin-button--small size-9',
    default: 'size-11',
    large: 'admin-button--large size-12',
};

/**
 * A label let to wrap below the admin's breakpoint: the button no wider than its row and as high
 * as its lines, with a little room over and under them, the lines centred. At or above the
 * breakpoint none of it applies and the button is its one line.
 */
const WRAP = 'max-desktop:h-auto max-desktop:max-w-full max-desktop:py-1.5 max-desktop:text-center max-desktop:whitespace-normal';

/** A button whose label wraps is never lower than its own height. */
const WRAP_LEAST: Record<ButtonSize, string> = {
    small: 'max-desktop:min-h-9',
    default: 'max-desktop:min-h-11',
    large: 'max-desktop:min-h-12',
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
        size?: ButtonSize;
        /** Drawn before the label, or alone with the label kept for a screen reader. */
        icon?: ReactNode;
        iconOnly?: boolean;
        /** Lets a label wider than a phone's row wrap below the admin's breakpoint. */
        wrap?: boolean;
        children: ReactNode;
    };

export function Button({
    variant = 'secondary',
    destructive = false,
    size = 'default',
    icon,
    iconOnly = false,
    wrap = false,
    busy = false,
    busyLabel,
    disabled = false,
    type = 'button',
    className,
    onClick,
    children,
    ...rest
}: Props) {
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
                'admin-button focus-ring inline-flex shrink-0 items-center justify-center gap-2 rounded-full text-body-sm font-bold whitespace-nowrap outline-none select-none disabled:cursor-not-allowed disabled:opacity-45 motion-safe:transition motion-safe:duration-200 motion-safe:ease-soft motion-safe:enabled:active:scale-98',
                `admin-button--${variant}`,
                boxOf(variant, size, iconOnly, wrap),
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
            <Face busy={busy} icon={icon} iconOnly={iconOnly}>
                {busy ? busyLabel : children}
            </Face>
        </button>
    );
}

/**
 * The button's box at its size: a link has none of its own, an icon-only button is the square,
 * and any other the padded height, with the wrap where it is told to.
 */
function boxOf(variant: ButtonVariant, size: ButtonSize, iconOnly: boolean, wrap: boolean): string {
    if (variant === 'link') {
        return '';
    }

    if (iconOnly) {
        return `admin-button--icon-only ${ICON_ONLY_SIZES[size]}`;
    }

    return wrap ? `${SIZES[size]} ${WRAP} ${WRAP_LEAST[size]}` : SIZES[size];
}

/**
 * What the button shows: the spinner while busy, else the icon, before the label, each hidden
 * from assistive technology; an icon-only button keeps the label for a screen reader alone.
 */
function Face({ busy, icon, iconOnly, children }: { busy: boolean; icon: ReactNode; iconOnly: boolean; children: ReactNode }) {
    const mark = busy ? (
        <span className="admin-button__spinner size-3.5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
    ) : (
        icon !== undefined && (
            <span className="admin-button__icon inline-flex shrink-0 [&_svg:not([class*='size-'])]:size-4.5" aria-hidden="true">
                {icon}
            </span>
        )
    );

    return (
        <>
            {mark}
            {iconOnly ? <span className="sr-only">{children}</span> : children}
        </>
    );
}
