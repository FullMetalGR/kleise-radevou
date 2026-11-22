import { Tick } from '@/components/glyphs';
import { cn } from '@/lib/utils';
import { CHECK_BOX } from './check-field';

/*
 * A checkbox drawn as a card, for a list of things to choose among: the members who perform a
 * service, the services a member performs. The card is the label around the live checkbox, so a
 * press anywhere on it is a press on the box, and the box keeps its place in the tab order and
 * its keys. A label names its box by everything inside it, which would read a long line under the
 * name before the box's state, once per card. So the box takes its name from the name alone and
 * its description from the line under it, each by id, and the label is left the pointer's work.
 *
 * The card is a notice's radius inside a line ring, which turns into a thicker ring in the theme
 * colour on the soft tint while its box is ticked. A chosen card says so three ways, never by
 * colour alone: the box's own tick, that ring, and a tick at its end. It takes the hover tint only
 * while its box can change, and a locked box makes the whole card read as locked to the pointer.
 * Each place reaches the card's parts by hooks of its own, handed in beside the card's own look.
 */

/** The list the cards stand in: as many columns of at least 17.5rem as there is room for, one where there is less. */
export const CHECK_CARDS = 'grid grid-cols-[repeat(auto-fill,minmax(min(17.5rem,100%),1fr))] gap-x-5 gap-y-3';

const CARD =
    'flex w-full min-w-0 cursor-pointer items-center gap-3 rounded-notice px-3.5 py-3 ring-[1.5px] ring-border motion-safe:transition motion-safe:duration-200 motion-safe:ease-soft has-enabled:hover:bg-muted has-checked:bg-muted/60 has-checked:ring-2 has-checked:ring-primary has-disabled:cursor-not-allowed';

type Props = {
    /** The box's id, which the name's and the line's ids are spelled from. */
    id: string;
    name: string;
    /** The line under the name that describes the box, or none. */
    description?: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
    className?: string;
    tickClassName?: string;
    nameClassName?: string;
    descriptionClassName?: string;
    markClassName?: string;
};

export function CheckCard({ id, name, description, checked, disabled = false, onChange, className, tickClassName, nameClassName, descriptionClassName, markClassName }: Props) {
    const nameId = `${id}-name`;
    const descriptionId = `${id}-description`;

    return (
        <label className={cn(CARD, className)}>
            <input
                type="checkbox"
                id={id}
                className={cn(CHECK_BOX, tickClassName)}
                checked={checked}
                disabled={disabled}
                aria-labelledby={nameId}
                aria-describedby={description === undefined ? undefined : descriptionId}
                onChange={(event) => onChange(event.target.checked)}
            />
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span id={nameId} className={cn('text-body font-semibold wrap-break-word', nameClassName)}>
                    {name}
                </span>
                {description !== undefined && (
                    <span id={descriptionId} className={cn('text-small wrap-break-word text-muted-foreground', descriptionClassName)}>
                        {description}
                    </span>
                )}
            </span>
            <span className={cn('inline-flex size-4 shrink-0 text-primary-deep', markClassName)}>{checked && <Tick />}</span>
        </label>
    );
}
