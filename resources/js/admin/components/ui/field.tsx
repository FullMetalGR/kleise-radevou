import { type ReactNode, type RefObject, useEffect, useRef } from 'react';
import { Alert } from '../glyphs';
import { cn } from '@/lib/utils';

/*
 * The field shell: a label, the control, and an optional line underneath. The label points at
 * the control by id and wraps nothing, because a wrapping label takes its accessible name from
 * everything inside it and a field with help would announce as label and explanation run
 * together. The line hangs off aria-describedby. An error replaces the help line, takes its
 * place in aria-describedby, and marks the control aria-invalid; a blank error is no error. The
 * label is 13.5px in the muted ink, the help line 13.5px at the body's weight, and the error
 * 13.5px in the destructive colour at the label's weight, starting with an alert icon so it never
 * rests on its colour alone. A compact field, a filter row's, draws all three at 12px.
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

/** The frame's look, the one spelling of it: the label, the control and the line in a column. */
const FRAME = 'admin-field flex flex-col gap-1.5';

/** The label's look, the one spelling of it: muted, 13.5px, and 12px and heavier in a compact field. */
const labelLook = (compact: boolean) => cn('admin-field__label text-muted-foreground', compact ? 'text-xs font-semibold' : 'text-label');

/** The help line's look, the one spelling of it: muted at the body's weight, 13.5px, and 12px in a compact field. */
const helpLook = (compact: boolean) => cn('admin-field__help', compact ? 'text-xs' : 'text-label', 'font-medium text-muted-foreground');

/**
 * The help line on its own, for a sentence that explains a part of a form but belongs to no one
 * control: a card's or a section's help, a status line under a control. The caller's class names
 * the line for itself; the look is the field's.
 */
export function HelpLine({ id, role, className, compact = false, children }: { id?: string; role?: 'status'; className?: string; compact?: boolean; children: ReactNode }) {
    return (
        <p id={id} role={role} className={cn(helpLook(compact), className)}>
            {children}
        </p>
    );
}

/**
 * The error line on its own, for a refusal that belongs to a group of controls and to no one
 * field: a list of ticks, a consent box, a check. The group points at the id through
 * aria-describedby; the look is the field's, the alert icon first. A line that can arrive while
 * the focus is on another control is an alert where its caller asks, so it is said at once.
 */
export function ErrorLine({ id, role, compact = false, children }: { id: string; role?: 'alert'; compact?: boolean; children: ReactNode }) {
    return (
        <p id={id} role={role} className={cn('admin-field__error flex items-start gap-1.5 text-destructive', compact ? 'text-xs font-semibold' : 'text-label font-semibold')}>
            <span className="flex h-lh items-center">
                <Alert />
            </span>
            {children}
        </p>
    );
}

/**
 * The frame and the label around something that is no control: a sentence standing where a control
 * will be, as while a list of choices loads. The label is plain text, since it has nothing to
 * point at, and looks as it will once the control arrives.
 */
export function FieldFrame({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className={FRAME}>
            <span className={labelLook(false)}>{label}</span>
            {children}
        </div>
    );
}

export function Field({ id, label, help, error, className, compact = false, children }: Props) {
    const failing = error !== undefined && error !== null && error.trim() !== '';
    const helped = !failing && help !== undefined && help !== '';
    const control: FieldControl = { id };

    if (failing) {
        control['aria-describedby'] = `${id}-error`;
        control['aria-invalid'] = true;
    } else if (helped) {
        control['aria-describedby'] = `${id}-help`;
    }

    return (
        <div className={cn(FRAME, className)}>
            <label htmlFor={id} className={labelLook(compact)}>
                {label}
            </label>
            {children(control)}
            {failing && (
                <ErrorLine id={`${id}-error`} compact={compact}>
                    {error}
                </ErrorLine>
            )}
            {helped && (
                <HelpLine id={`${id}-help`} compact={compact}>
                    {help}
                </HelpLine>
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
