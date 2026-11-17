import { router } from '@inertiajs/react';
import type { ShellWords } from '@/lib/strings';
import { type Failure, owningFailure } from '@/lib/visits';
import type { HelpProps } from './types';

/*
 * The panel's one way to the server. Every request is an async partial visit naming only the
 * props it wants back, so the screen the user is on keeps its scroll, its state and its flash,
 * and the framework's progress bar stays off: a read asks the screen's own address again, a
 * write posts to a help route, whose answer goes back to that address with the thread it was
 * about.
 *
 * A request ends in one of four ways: landed, refused, cancelled (another visit to another
 * address cancels every async request in flight, a navigation included) or lost with the
 * connection. Its ending runs on all four, so nothing in the panel is left waiting on a request
 * that will never land. A cancelled request ends with neither an answer nor a refusal; a lost one
 * is refused with no status, the server having said nothing. What landing wants to do next, a
 * second request most often, waits until the first has ended: started from inside the answer it
 * would cancel the request still finishing.
 */

/** Asks for one more step once the request has ended. */
type Then = (next: () => void) => void;

type Ask = {
    /** Where the request goes: null reads the screen the user is on again, a path posts there. */
    to: string | null;
    data?: Record<string, string>;
    only: readonly string[];
    /** What landing does with the props it asked for; the others may be left from before. */
    landed: (props: HelpProps, then: Then) => void;
    refused: (failure: Failure) => void;
    /** Runs on every ending, before the step landing asked for. */
    ended: () => void;
};

/** The path every help route starts with. */
export const HELP_URL = '/admin/help';

export function ask(request: Ask, shell: ShellWords): void {
    let next: (() => void) | null = null;
    const owned = owningFailure((failure) => request.refused(failure), shell);
    const options = {
        only: [...request.only],
        async: true,
        preserveState: true,
        preserveScroll: true,
        onStart: owned.onStart,
        onSuccess: (page: { props: object }) => {
            request.landed(page.props as HelpProps, (then) => {
                next = then;
            });
        },
        onFinish: () => {
            owned.onFinish();
            request.ended();
            next?.();
        },
    };

    if (request.to === null) {
        router.reload(options);
    } else {
        router.post(request.to, request.data ?? {}, options);
    }
}
