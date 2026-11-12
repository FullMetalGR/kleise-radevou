import { Button } from '@/components/ui/button';
import type { LanguageChoice } from '../types';

/*
 * The top bar's language control: English and Greek, each labelled by its language's own name as
 * the server reads it and carrying that language as its lang, so it is read in the language it
 * names; the one the page is answered in is pressed. Pressing the other writes the signed-in
 * user's own language and the page comes back in it; the pressed one stays busy, its own name its
 * busy label, until the server's answer arrives, and nothing is pressed meanwhile. The choice
 * lives on the user's row, unlike the theme, which stays in the browser. The server shares the
 * choices to a signed-in user alone, never to a support session, so the shell renders this only
 * where it has them.
 */

type Props = {
    choices: LanguageChoice[];
    /** The language the page is answered in, the shared props' language. */
    current: string;
    /** The language being written, while its write is in flight, else null. */
    choosing: string | null;
    onChoose: (language: string) => void;
};

export function LanguageControl({ choices, current, choosing, onChoose }: Props) {
    return (
        <div className="admin-language flex items-center gap-1">
            {choices.map((choice) => (
                <Button
                    key={choice.value}
                    variant="tertiary"
                    className="admin-language__choice"
                    lang={choice.value}
                    aria-pressed={choice.value === current}
                    busy={choosing === choice.value}
                    busyLabel={choice.name}
                    onClick={() => {
                        if (choice.value !== current && choosing === null) {
                            onChoose(choice.value);
                        }
                    }}
                >
                    {choice.name}
                </Button>
            ))}
        </div>
    );
}
