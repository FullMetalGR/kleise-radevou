import { Head } from '@inertiajs/react';
import { type ShellKey, useShell } from '@/lib/strings';

/*
 * An unknown address under the admin: a 404 page inside the shell, not a blank panel, with the
 * rail beside it as the way on. Its words are the shell's not_found group, in the page's
 * language; its controller names no section of its own.
 */

/** The page's title, also its heading: a key of the shell's section. */
export const NOT_FOUND_HEADING = 'not_found.title' satisfies ShellKey;

export default function NotFound() {
    const shell = useShell();

    return (
        <>
            <Head title={shell.text(NOT_FOUND_HEADING)} />
            <h1 className="text-2xl font-semibold">{shell.text(NOT_FOUND_HEADING)}</h1>
            <p className="mt-2 text-muted-foreground">{shell.text('not_found.sentence')}</p>
        </>
    );
}
