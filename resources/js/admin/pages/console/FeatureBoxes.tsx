import { useState } from 'react';
import { CheckField } from '@/components/ui/check-field';

/*
 * The features as the console offers them: one checkbox per feature with its name and what it
 * opens, the server's choices in the server's order, and the server's refusal of the list under
 * them. The free tenant's form and the detail's Change features both post the checked boxes as one
 * list in that order.
 */

/** One feature as the console offers it: its slug, its name and what it opens. */
export type FeatureChoice = { value: string; label: string; help: string };

/** The boxes checked, kept in the tab's memory until they are posted. */
export function useFeatureChoice(initial: string[]): { chosen: string[]; toggle: (value: string, checked: boolean) => void } {
    const [chosen, setChosen] = useState<string[]>(initial);

    return { chosen, toggle: (value, checked) => setChosen((was) => [...was.filter((held) => held !== value), ...(checked ? [value] : [])]) };
}

/** The checked slugs as the body names them, in the order the server offered them. */
export function chosenIn(offered: FeatureChoice[], chosen: string[]): string[] {
    return offered.map((choice) => choice.value).filter((value) => chosen.includes(value));
}

type Props = {
    idPrefix: string;
    offered: FeatureChoice[];
    chosen: string[];
    onToggle: (value: string, checked: boolean) => void;
    error: string | null;
    errorId: string;
    className: string;
};

export function FeatureBoxes({ idPrefix, offered, chosen, onToggle, error, errorId, className }: Props) {
    return (
        <>
            {offered.map((choice) => (
                <CheckField
                    key={choice.value}
                    id={`${idPrefix}-${choice.value}`}
                    className={className}
                    label={choice.label}
                    help={choice.help}
                    checked={chosen.includes(choice.value)}
                    onChange={(checked) => onToggle(choice.value, checked)}
                />
            ))}
            {error !== null && (
                <p id={errorId} className="admin-field__error text-destructive text-sm">
                    {error}
                </p>
            )}
        </>
    );
}
