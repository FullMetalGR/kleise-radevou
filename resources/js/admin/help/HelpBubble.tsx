import { usePage } from '@inertiajs/react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { useEffect, useRef } from 'react';
import { FORCED_EDGE } from '@/components/ui/input';
import { clockOf, type TenantClock } from '@/lib/format';
import { useShell } from '@/lib/strings';
import { useDesktop } from '@/lib/viewport';
import type { SharedProps } from '@/types';
import { Panel } from './Panel';
import { useHelpSession } from './session';
import type { HelpBubble as Bubbled } from './types';

/*
 * The round "?" in the bottom corner of every admin screen, and the panel it opens. The bubble is
 * a floating circle in the theme colour, the "?" a character in the display face and not an icon.
 * It sits under every dialog and its backdrop, so a dialog open over the screen covers it: it
 * neither takes the dialog's focus nor hears its Escape. A red dot at its top right, ringed in the
 * page's colour, says a ticket has a reply this user has not read, in words too, as its name. A
 * screen whose props carry no help, a support session's, shows no bubble at all.
 *
 * A link from a mail opens the admin on a ticket: the panel opens on its thread once no dialog is
 * open over the screen, the setup wizard first of all, so it never takes a dialog's place.
 */

export function HelpBubble() {
    const page = usePage<SharedProps>();
    const help = page.props.help;

    if (help === undefined) {
        return null;
    }

    return <Bubble help={help} path={page.url.split('?')[0] ?? page.url} clock={clockOf(page.props)} />;
}

const MODAL = '[aria-modal="true"]';

/** Runs once no dialog is open over the screen, now or as soon as the last one goes; the answer cancels the wait. */
function whenNoModal(run: () => void): () => void {
    let observer: MutationObserver | null = null;

    const check = () => {
        if (document.querySelector(MODAL) !== null) {
            return false;
        }

        observer?.disconnect();
        observer = null;
        run();

        return true;
    };

    const timer = window.setTimeout(() => {
        if (!check()) {
            observer = new MutationObserver(() => check());
            observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-modal'] });
        }
    }, 0);

    return () => {
        window.clearTimeout(timer);
        observer?.disconnect();
    };
}

/**
 * Opens the panel on the ticket a link from a mail named. The wait is kept from the moment the
 * ticket is seen: an answer that brings the props again without it, as the first one of a dialog
 * open over the screen does, leaves the wait standing, and only another ticket or the bubble's
 * going ends it.
 */
function useDeepLink(open: string | null, openOn: (uuid: string) => void): void {
    const latest = useRef(openOn);
    const waiting = useRef<(() => void) | null>(null);

    useEffect(() => {
        latest.current = openOn;
    });

    useEffect(() => {
        if (open === null) {
            return;
        }

        waiting.current?.();
        waiting.current = whenNoModal(() => latest.current(open));
    }, [open]);

    useEffect(() => () => waiting.current?.(), []);
}

/** Closes the panel when the screen changes under it. */
function useClosedOnLeave(path: string, close: () => void): void {
    const was = useRef(path);

    useEffect(() => {
        if (was.current !== path) {
            was.current = path;
            close();
        }
    }, [path, close]);
}

function Bubble({ help, path, clock }: { help: Bubbled; path: string; clock: TenantClock }) {
    const shell = useShell();
    const desktop = useDesktop();
    const session = useHelpSession();
    const bubble = useRef<HTMLButtonElement>(null);
    useDeepLink(help.open, session.openOn);
    useClosedOnLeave(path, session.close);

    return (
        <DialogPrimitive.Root open={session.open} onOpenChange={session.openChange} modal={!desktop}>
            <DialogPrimitive.Trigger asChild>
                <button
                    ref={bubble}
                    type="button"
                    className={`admin-help-bubble focus-ring fixed right-4 bottom-4 z-40 flex size-12 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-float outline-none hover:bg-primary-deep print:hidden motion-safe:transition-colors motion-safe:duration-200 motion-safe:ease-soft ${FORCED_EDGE}`}
                    aria-label={shell.text(help.unread ? 'help.unread' : 'help.name')}
                    aria-busy={session.opening ? true : undefined}
                >
                    <span className="font-display text-xl leading-none font-bold" aria-hidden="true">
                        ?
                    </span>
                    {help.unread && <span className="admin-help-bubble__dot absolute top-0 right-0 size-3 rounded-full bg-destructive ring-2 ring-background" aria-hidden="true" />}
                </button>
            </DialogPrimitive.Trigger>
            {session.words !== null && <Panel session={session} words={session.words} help={help} desktop={desktop} clock={clock} bubble={bubble} />}
        </DialogPrimitive.Root>
    );
}
