import type { ShellWords } from './strings';

/*
 * How the admin names the platform's operator acting in a support session: "Support", and the
 * operator in brackets where the operator is known, the spelling the server's
 * App\Http\AdminProps::SUPPORT_NAME gives auth.user's name, one key of the shell's section for
 * both, :operator the name. The booking dialog's trail spells it from here, so the client spells it
 * once.
 */

/** The word alone, for a trail entry whose operator is gone, a key of the shell's section. */
export const SUPPORT_ACTOR = 'shared.support.actor';

/** Support named for the operator, a key of the shell's section with :operator. */
const SUPPORT_NAMED = 'shared.support.named';

/** Support named for the operator acting in the session. */
export function supportNamed(operator: string, shell: ShellWords): string {
    return shell.text(SUPPORT_NAMED, { operator });
}
