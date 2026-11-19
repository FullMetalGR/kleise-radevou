import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
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
            <PageHeader title={shell.text(NOT_FOUND_HEADING)} lead={shell.text('not_found.sentence')} />
        </>
    );
}
