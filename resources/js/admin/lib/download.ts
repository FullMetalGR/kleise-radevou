import { useState } from 'react';
import { useToasts } from '@/toast/toasts';

/*
 * A file the admin asks the server for and hands to the browser to save, the export buttons' one
 * road: a customer's data from their page and everything the business holds from the Settings
 * Danger zone.
 *
 * The page fetches the file rather than following a bare link, so the button can be busy while
 * the request is on its way and a failure can be a sentence rather than an error page in a new
 * tab. The request asks for JSON, so a session that lapsed meanwhile is answered 401 rather than
 * sent to the sign-in page, whose HTML would otherwise be saved as the file; an answer that is
 * not of the file's type is a failure for the same reason. The file the server answers is handed
 * to the browser whole under the name the server gave it. Nothing is optimistic: the control is
 * busy until the answer is in, and a failure is an error toast, the envelope's sentence where
 * the server worded one and the caller's own otherwise.
 */

/** What a download asks for and says: the type the file must be, its name where the answer gives none, and the failure's sentence. */
export type DownloadWords = { type: string; fallback: string; failure: string };

/** The file name a Content-Disposition header gives, quoted or not, or null where it gives none. */
export function fileNameOf(disposition: string | null): string | null {
    const named = /filename="?([^";]+)"?/i.exec(disposition ?? '');

    return named?.[1] ?? null;
}

/** The envelope's sentence, where the answer carried one. */
function detailOf(answer: unknown): string | null {
    const data: unknown = typeof answer === 'object' && answer !== null ? (answer as { data?: unknown }).data : null;
    const detail: unknown = typeof data === 'object' && data !== null ? (data as { detail?: unknown }).detail : null;

    return typeof detail === 'string' && detail.trim() !== '' ? detail : null;
}

/** A refusal carrying the sentence a toast shows. */
class Refused extends Error {}

/** Hands a file to the browser to save, as a link with a download name pressed once. */
function save(file: Blob, name: string): void {
    const href = URL.createObjectURL(file);
    const link = document.createElement('a');

    link.href = href;
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(href), 0);
}

async function fetchAndSave(url: string, words: DownloadWords): Promise<void> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

    if (!response.ok) {
        throw new Refused(detailOf(await response.json().catch(() => null)) ?? words.failure);
    }

    if (!(response.headers.get('Content-Type') ?? '').includes(words.type)) {
        throw new Refused(words.failure);
    }

    save(await response.blob(), fileNameOf(response.headers.get('Content-Disposition')) ?? words.fallback);
}

/** One download control's state: whether its request is on its way, and the press that sends it. */
export function useDownload(words: DownloadWords): { busy: boolean; start: (url: string) => void } {
    const toasts = useToasts();
    const [busy, setBusy] = useState(false);

    const start = (url: string) => {
        setBusy(true);
        fetchAndSave(url, words)
            .catch((failure: unknown) => toasts.push('error', failure instanceof Refused ? failure.message : words.failure))
            .finally(() => setBusy(false));
    };

    return { busy, start };
}
