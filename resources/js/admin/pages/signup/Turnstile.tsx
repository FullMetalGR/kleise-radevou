import { useEffect, useRef } from 'react';

/*
 * Cloudflare Turnstile's widget on the signup form. The script's address and the site key come
 * from the server, which hands them to the signup page alone and to no other, so no other page
 * loads the script. The first widget to mount adds the script's tag to the document head, once
 * per page, and every widget renders explicitly into its own box once the script has loaded. The
 * token the widget hands back goes to onToken; an expired or failed challenge hands back the
 * empty string, so the form never posts a token the widget has given up. Unmounting removes the
 * widget, so mounting it anew, as the form does after a refused answer, renders a fresh challenge
 * with a fresh token.
 */

export type TurnstileWidget = { siteKey: string; script: string };

type TurnstileApi = {
    render: (box: HTMLElement, options: { sitekey: string; callback: (token: string) => void; 'expired-callback': () => void; 'error-callback': () => void }) => string | null | undefined;
    remove: (widget: string) => void;
};

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

/** Resolves once Cloudflare's script has run, adding its tag the first time it is asked for. */
function scriptLoaded(src: string): Promise<void> {
    if (window.turnstile !== undefined) {
        return Promise.resolve();
    }

    const present = [...document.scripts].find((tag) => tag.getAttribute('src') === src);
    const tag = present ?? document.createElement('script');

    if (present === undefined) {
        tag.src = src;
        tag.async = true;
        document.head.append(tag);
    }

    return new Promise((resolve) => tag.addEventListener('load', () => resolve(), { once: true }));
}

export function Turnstile({ siteKey, script, onToken }: TurnstileWidget & { onToken: (token: string) => void }) {
    const box = useRef<HTMLDivElement>(null);
    const handOver = useRef(onToken);

    useEffect(() => {
        handOver.current = onToken;
    });

    useEffect(() => {
        let mounted = true;
        let widget: string | null | undefined;

        void scriptLoaded(script).then(() => {
            const api = window.turnstile;

            if (!mounted || api === undefined || box.current === null) {
                return;
            }

            widget = api.render(box.current, {
                sitekey: siteKey,
                callback: (token) => handOver.current(token),
                'expired-callback': () => handOver.current(''),
                'error-callback': () => handOver.current(''),
            });
        });

        return () => {
            mounted = false;

            if (widget !== null && widget !== undefined) {
                window.turnstile?.remove(widget);
            }
        };
    }, [siteKey, script]);

    return <div ref={box} className="admin-signup-turnstile" />;
}
