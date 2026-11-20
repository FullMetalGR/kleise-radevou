import { HelpLine } from './field';
import { cn } from '@/lib/utils';

/*
 * A checkbox with its label beside it and, where there is something to explain, a help line under
 * both: the label points at the box by id and wraps nothing, and the help hangs off
 * aria-describedby, as the field shell does for every other control, so the box announces as its
 * label with the help read after. A box with no help, a view switch whose label says it all,
 * draws no line and points at none. The box is 20px, tinted by the theme colour, and the help is
 * drawn as every field draws its help. A place that reads the box by a hook of its own names it
 * in tickClassName, beside the box's own.
 */

/**
 * The box's look, the one spelling of it, for the check field and for a tick a screen writes by
 * hand beside its own label: 20px, tinted by the theme colour, the field's focus outline. A
 * disabled box keeps the browser's own disabled paint and takes no dimming on top of it: that
 * paint is already faint, and a locked box that is ticked has to go on reading as ticked.
 */
export const CHECK_BOX = 'size-5 shrink-0 cursor-pointer accent-primary focus-ring-field disabled:cursor-not-allowed';

type Props = {
    id: string;
    label: string;
    help?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    className?: string;
    tickClassName?: string;
};

export function CheckField({ id, label, help, checked, onChange, className, tickClassName }: Props) {
    return (
        <div className={cn('admin-check-field flex flex-col gap-1.5', className)}>
            <div className="flex items-center gap-3">
                <input
                    type="checkbox"
                    id={id}
                    className={cn('admin-check-field__tick', CHECK_BOX, tickClassName)}
                    checked={checked}
                    aria-describedby={help === undefined ? undefined : `${id}-help`}
                    onChange={(event) => onChange(event.target.checked)}
                />
                <label htmlFor={id} className="admin-check-field__label text-body font-semibold">
                    {label}
                </label>
            </div>
            {help !== undefined && <HelpLine id={`${id}-help`}>{help}</HelpLine>}
        </div>
    );
}
