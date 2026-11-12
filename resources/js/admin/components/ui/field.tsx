import { type ReactNode, type RefObject, useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

/*
 * The field shell: a label, the control, and an optional line underneath. The label points at
 * the control by id and wraps nothing, because a wrapping label takes its accessible name from
 * everything inside it and a field with help would announce as label and explanation run
 * together. The line hangs off aria-describedby. An error replaces the help line, takes its
 * place in aria-describedby, and marks the control aria-invalid; a blank error is no error. The
 * help line is 12px; a compact field, a filter row's, draws its label and its error small too.
 *
 * The control is rendered by the caller from the attributes the shell hands it, so any control,
 * native or ours, is wired the same way.
 */

/** The attributes the shell hands its control, so a composite control can spread them onto its input. */
export type FieldControl = {
    id: string;
    'aria-describedby'?: string;
    'aria-invalid'?: true;
};

type Props = {
    id: string;
    label: string;
    help?: string;
    error?: string | null;
    className?: string;
    /** A filter row's field: the label and the line under it small. */
    compact?: boolean;
    children: (control: FieldControl) => ReactNode;
};

/** The help line's look, the one spelling of it: 12px and muted, as the boards draw it. */
const HELP = 'admin-field__help text-xs text-muted-foreground';

/**
 * The help line on its own, for a sentence that explains a part of a form but belongs to no one
 * control: a card's or a section's help, a status line under a control. The caller's class names
 * the line for itself; the look is the field's.
 */
export function HelpLine({ id, role, className, children }: { id?: string; role?: 'status'; className?: string; children: ReactNode }) {
    return (
        <p id={id} role={role} className={cn(HELP, className)}>
            {children}
        </p>
    );
}

export function Field({ id, label, help, error, className, compact = false, children }: Props) {
    const failing = error !== undefined && error !== null && error.trim() !== '';
    const line = failing
        ? { id: `${id}-error`, text: error, className: cn('admin-field__error text-destructive', compact ? 'text-xs' : 'text-sm') }
        : help === undefined || help === ''
          ? null
          : { id: `${id}-help`, text: help, className: HELP };
    const control: FieldControl = { id };

    if (line !== null) {
        control['aria-describedby'] = line.id;
    }

    if (failing) {
        control['aria-invalid'] = true;
    }

    return (
        <div className={cn('admin-field flex flex-col gap-1', className)}>
            <label htmlFor={id} className={cn('admin-field__label font-medium', compact ? 'text-xs' : 'text-sm')}>
                {label}
            </label>
            {children(control)}
            {line !== null && (
                <p id={line.id} className={line.className}>
                    {line.text}
                </p>
            )}
        </div>
    );
}

/**
 * The focus move, spelled once for every form whose fields carry errors: after a refused submit,
 * focus moves to the first control inside the form the shell marked aria-invalid, in document
 * order rather than the errors' key order, so its error line is announced through
 * aria-describedby as the field takes focus. A refusal marking no field, a notice or a toast,
 * leaves focus where it was.
 *
 * `refusal` is whatever changes once per refused submit and at no other time: the errors bag the
 * server's answer set, which the form helper hands over new with each answer and keeps as it is
 * across keystrokes, or a count the form bumps when its own checks refuse. So the move happens
 * once per answer and never on a later render, and an errors bag that outlives the answer never
 * steals focus back from a field the person is typing in. The first render moves nothing, so a
 * form that opens on a problem does not take focus on load.
 *
 * The container is a form by default, or any element holding a group of fields that lives inside
 * a larger form, as the Payments section's credentials do inside the Settings form: the move then
 * stays inside that group, which counts its own refusals.
 */
export function useFocusFirstFailing<T extends HTMLElement = HTMLFormElement>(refusal: unknown): RefObject<T | null> {
    const form = useRef<T>(null);
    const seen = useRef(refusal);

    useEffect(() => {
        if (Object.is(seen.current, refusal)) {
            return;
        }

        seen.current = refusal;
        form.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
    }, [refusal]);

    return form;
}
