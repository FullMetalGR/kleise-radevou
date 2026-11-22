import { createContext, type ReactNode, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { Alert, Cross } from '@/components/glyphs';
import { Button } from '@/components/ui/button';
import { FORCED_EDGE } from '@/components/ui/input';
import { useShell } from '@/lib/strings';
import type { Flash } from '../types';

/*
 * Toasts: an error toast stays until it is dismissed, and every other toast clears itself after a
 * few seconds. An operator told that something could not be done needs the sentence on screen until
 * they have read it, and a timer does not know when that is.
 *
 * The API is one hook: useToasts().push(kind, message) from anywhere under the provider, and
 * dismiss(id). A kind the admin does not recognise is shown as given and clears like any other
 * non-error; the message is always shown verbatim. The toaster's own word, Dismiss, is the shell's,
 * in the page's language on every host.
 */

export const AUTO_CLEAR_MS = 5000;

/** The toast's dismiss control, a key of the shell's section. */
export const DISMISS = 'toaster.dismiss';

type Toast = { id: number; kind: string; message: string };

type Toasts = {
    toasts: Toast[];
    push: (kind: string, message: string) => void;
    dismiss: (id: number) => void;
};

const ToastContext = createContext<Toasts | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const next = useRef(1);
    const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());

    const dismiss = useCallback((id: number) => {
        const timer = timers.current.get(id);

        if (timer !== undefined) {
            clearTimeout(timer);
            timers.current.delete(id);
        }

        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    const push = useCallback(
        (kind: string, message: string) => {
            const id = next.current++;

            setToasts((current) => [...current, { id, kind, message }]);

            if (kind !== 'error') {
                timers.current.set(
                    id,
                    setTimeout(() => dismiss(id), AUTO_CLEAR_MS),
                );
            }
        },
        [dismiss],
    );

    useEffect(() => {
        const pending = timers.current;

        return () => {
            for (const timer of pending.values()) {
                clearTimeout(timer);
            }
        };
    }, []);

    return (
        <ToastContext.Provider value={{ toasts, push, dismiss }}>
            {children}
            <Toaster toasts={toasts} dismiss={dismiss} />
        </ToastContext.Provider>
    );
}

export function useToasts(): Toasts {
    const toasts = useContext(ToastContext);

    if (toasts === null) {
        throw new Error('useToasts() is used outside the ToastProvider.');
    }

    return toasts;
}

/**
 * The toaster stacks above every modal layer, z-50 each, so a toast answering a press made inside
 * a dialog shows over that dialog's backdrop rather than dimmed beneath it, and its Dismiss takes
 * the press: the modal refuses a press outside its frame, so the dialog stays open. Below the
 * admin's breakpoint, it spans the screen between the two side margins at the top, since a dialog
 * filling the screen pins its actions to the bottom, and an error toast, which stays until
 * dismissed, would sit over them there.
 *
 * Each toast is a message in the ink fill, rounded to hold a second line, with a small round
 * button at its end to dismiss it, named Dismiss and showing a cross. The message may narrow below
 * its longest word and break inside it, so a long unbroken value, an address say, wraps rather
 * than pushing the button out of the toast. An error toast starts with the alert mark, since a
 * red word on the ink would be too faint to read and the role alone is not seen. The button's
 * focus outline takes the message's own ink, as the theme colour it otherwise reads can be as dark
 * as the fill. Where the system forces its own colours the fill is repainted as the page and the
 * shadow dropped, so there a toast takes a real line round it.
 */
function Toaster({ toasts, dismiss }: { toasts: Toast[]; dismiss: (id: number) => void }) {
    const shell = useShell();

    return (
        <div className="admin-toaster pointer-events-none fixed right-4 bottom-4 z-[60] flex w-80 flex-col gap-2 max-desktop:top-4 max-desktop:bottom-auto max-desktop:left-4 max-desktop:w-auto" aria-live="polite">
            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    role={toast.kind === 'error' ? 'alert' : 'status'}
                    data-kind={toast.kind}
                    className={`admin-toast pointer-events-auto flex items-center gap-2 rounded-inner bg-foreground py-2 pr-2 pl-4 text-body-sm font-semibold text-background shadow-float ${FORCED_EDGE}`}
                >
                    {toast.kind === 'error' && <Alert />}
                    <p className="min-w-0 flex-1 py-2 wrap-anywhere">{toast.message}</p>
                    <Button
                        variant="tertiary"
                        size="small"
                        iconOnly
                        icon={<Cross />}
                        className="admin-toast-dismiss text-background focus-visible:outline-background enabled:hover:bg-background/15"
                        onClick={() => dismiss(toast.id)}
                    >
                        {shell.text(DISMISS)}
                    </Button>
                </div>
            ))}
        </div>
    );
}

/**
 * The flash prop consumed into a toast, once per visit that carried one.
 */
export function FlashToast({ flash }: { flash: Flash | null }) {
    const { push } = useToasts();

    useEffect(() => {
        if (flash !== null) {
            push(flash.kind, flash.message);
        }
    }, [flash, push]);

    return null;
}
