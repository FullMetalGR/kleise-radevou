import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/*
 * Text and textarea. The date, time, datetime and number types render in the monospace face so
 * figures line up with the table columns beside them. A text input stands 32px high, the
 * control height and the button's; the textarea keeps its own.
 */

const BASE = 'admin-input w-full rounded-md border bg-background px-3 py-1.5 text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-60';

const FIGURES = new Set(['date', 'time', 'datetime-local', 'number']);

export function TextInput({ type = 'text', className, ...rest }: ComponentProps<'input'>) {
    return <input type={type} className={cn(BASE, 'h-8 py-1', FIGURES.has(type) && 'admin-input--figures font-mono', className)} {...rest} />;
}

export function Textarea({ className, ...rest }: ComponentProps<'textarea'>) {
    return <textarea className={cn(BASE, 'min-h-20', className)} {...rest} />;
}
