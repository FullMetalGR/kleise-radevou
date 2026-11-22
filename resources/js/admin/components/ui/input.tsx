import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/*
 * Text and textarea, and the look every field of the kit shares. A field stands 46px high on the
 * card fill inside a 1.5px ring of the line colour, the ring darker under the pointer and the
 * field's focus outline on the keyboard; an invalid field takes a 2px ring of the destructive
 * colour, a disabled one is dimmed on the soft fill and a read-only one sits on the soft fill,
 * neither darkening under the pointer. The date, time, datetime and number types set their
 * figures in fixed-width numerals so they line up with the table columns beside them, in the
 * body face. A dense row passes its own height in className; the textarea keeps its own.
 */

/**
 * A real 1px line round a part whose only edge is a ring or a shadow, drawn only where the
 * person's system forces its own colours. There every shadow is dropped and every fill repainted
 * as the page, so a field, a button, a menu, a dialog or a toast would stand on the page with no
 * edge at all; the system paints the line in its own colour for text.
 */
export const FORCED_EDGE = 'forced-colors:border';

/** The shared look, without a height: the select and the pickers' triggers wear it too. */
export const FIELD = `w-full rounded-field bg-card px-3.5 text-body text-foreground inset-ring-[1.5px] inset-ring-border outline-none focus-ring-field placeholder:text-ink-tertiary aria-invalid:inset-ring-2 aria-invalid:inset-ring-destructive disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-45 motion-safe:transition-shadow ${FORCED_EDGE}`;

/** A single-line field's height. */
export const FIELD_HEIGHT = 'h-11.5';

/** The darker ring under the pointer for a field that is pressed, never on a disabled one. */
export const PRESSED_HOVER = 'enabled:hover:not-aria-invalid:inset-ring-ink-tertiary';

/**
 * A field typed into: read-only to the browser when it is read-only or disabled, so either sits on
 * the soft fill. The darker ring under the pointer asks for a field that is enabled and one that is
 * not read-only, each in its own right, so neither a disabled nor a read-only field darkens.
 */
export const TYPED = 'read-only:bg-muted enabled:not-read-only:hover:not-aria-invalid:inset-ring-ink-tertiary';

/** Fixed-width numerals for a field that holds figures. */
export const FIGURES_LOOK = 'admin-input--figures tabular-nums';

const FIGURES = new Set(['date', 'time', 'datetime-local', 'number']);

export function TextInput({ type = 'text', className, ...rest }: ComponentProps<'input'>) {
    return <input type={type} className={cn('admin-input', FIELD, TYPED, FIELD_HEIGHT, FIGURES.has(type) && FIGURES_LOOK, className)} {...rest} />;
}

export function Textarea({ className, ...rest }: ComponentProps<'textarea'>) {
    return <textarea className={cn('admin-input', FIELD, TYPED, 'min-h-20 resize-y py-3', className)} {...rest} />;
}
