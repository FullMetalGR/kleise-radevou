import { cn } from '@/lib/utils';

/*
 * A checkbox with its label beside it and a help line under both: the label points at the box
 * by id and wraps nothing, and the help hangs off aria-describedby, as the field shell does
 * for every other control, so the box announces as its label with the help read after.
 */

type Props = {
    id: string;
    label: string;
    help: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    className?: string;
};

export function CheckField({ id, label, help, checked, onChange, className }: Props) {
    return (
        <div className={cn('admin-check-field flex flex-col gap-1', className)}>
            <div className="flex items-center gap-2">
                <input type="checkbox" id={id} className="admin-check-field__tick" checked={checked} aria-describedby={`${id}-help`} onChange={(event) => onChange(event.target.checked)} />
                <label htmlFor={id} className="admin-check-field__label text-sm font-medium">
                    {label}
                </label>
            </div>
            <p id={`${id}-help`} className="admin-field__help text-sm text-muted-foreground">
                {help}
            </p>
        </div>
    );
}
