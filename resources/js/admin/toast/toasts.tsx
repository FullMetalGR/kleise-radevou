import { createContext, type ReactNode, useCallback, useContext, useEffect, useRef, useState } from 'react';
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
                    className={`admin-toast pointer-events-auto flex items-start gap-3 rounded-lg border bg-card p-3 text-sm text-card-foreground shadow-md ${toast.kind === 'error' ? 'border-destructive text-destructive' : ''}`}
                >
                    <p className="flex-1">{toast.message}</p>
                    <button type="button" className="admin-toast-dismiss text-muted-foreground underline" onClick={() => dismiss(toast.id)}>
                        {shell.text(DISMISS)}
                    </button>
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
