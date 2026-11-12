import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Confirm } from '@/components/ui/confirm';
import { Field } from '@/components/ui/field';
import { Textarea } from '@/components/ui/input';
import { type BookingsKey, useBookings } from './words';

/*
 * The booking dialog's action bar. It renders exactly the list the server computed for this viewer
 * and this booking, BookingActions, in the server's order, and holds no rule of its own about
 * which action applies when: a name it does not know renders verbatim and posts to the route of
 * the same name, and the handler behind every button refuses again.
 *
 * Approve, Completed and No-show fire at once. Reject opens a styled overlay with an optional
 * reason, reset each time it opens, a destructive confirm that fires with whatever is in the box,
 * the empty string included, and Keep it; Cancel opens a confirm saying it cannot be undone.
 * Dismissing either fires nothing. Both overlays are the admin's one Confirm (components/ui), each
 * a modal of its own over the dialog, so one Escape peels one layer. Each lands focus on its first
 * control, which is never the destructive one: the reason box, and Keep it where there is no box.
 *
 * Nothing is optimistic: the press hands the act to the screen, the pressed button shows busy
 * until the server's answer has landed, and what the booking now is arrives through the reload. An
 * overlay whose confirm is in flight stays up until then: Keep it, Escape and the backdrop all
 * refuse, because dismissing it would hide the busy state while the request still lands, and Keep
 * it says so in text beside it, as every disabled control does.
 *
 * Every word is a key of the bookings section: an action's label and busy word by its name, and
 * each confirm's title, pending line and the rest whole; a confirm's button reads its action's
 * label and busy word.
 */

/** Hands an action to the screen: its route's last segment, the form body, and what to do once the visit has finished. */
export type Act = (action: string, body: Record<string, string>, done: () => void) => void;

const LABELS: Readonly<Record<string, BookingsKey>> = {
    approve: 'actions.approve',
    reject: 'actions.reject',
    cancel: 'actions.cancel',
    complete: 'actions.complete',
    'no-show': 'actions.no_show',
};

const BUSY_LABELS: Readonly<Record<string, BookingsKey>> = {
    approve: 'busy.approve',
    reject: 'busy.reject',
    cancel: 'busy.cancel',
    complete: 'busy.complete',
    'no-show': 'busy.no_show',
};

/** An action's word from a table of keys, verbatim where the table holds none. */
function wordOf(table: Readonly<Record<string, BookingsKey>>, action: string, text: (key: BookingsKey) => string): string {
    const key = Object.hasOwn(table, action) ? table[action] : undefined;

    return key === undefined ? action : text(key);
}

type Prompt = 'reject' | 'cancel' | null;

type Props = {
    actions: string[];
    /** The action whose visit is in flight, or null. */
    busy: string | null;
    onAct: Act;
};

function nothing(): void {}

export function ActionBar({ actions, busy, onAct }: Props) {
    const words = useBookings();
    const [prompt, setPrompt] = useState<Prompt>(null);
    const [reason, setReason] = useState('');
    const dismiss = () => setPrompt(null);

    const choose = (action: string) => {
        if (action === 'reject') {
            setReason('');
            setPrompt('reject');

            return;
        }

        if (action === 'cancel') {
            setPrompt('cancel');

            return;
        }

        onAct(action, {}, nothing);
    };

    return (
        <div className="admin-booking-actions mr-auto flex flex-wrap gap-2">
            {actions.map((action) => (
                <Button
                    key={action}
                    variant={action === 'approve' ? 'primary' : 'secondary'}
                    destructive={action === 'cancel'}
                    className={`admin-booking-action admin-booking-action--${action}`}
                    busy={busy === action && prompt === null}
                    busyLabel={wordOf(BUSY_LABELS, action, words.text)}
                    onClick={() => choose(action)}
                >
                    {wordOf(LABELS, action, words.text)}
                </Button>
            ))}
            <Confirm
                open={prompt === 'reject'}
                title={words.text('reject.title')}
                name="admin-reject-prompt"
                confirmLabel={words.text('actions.reject')}
                busyLabel={words.text('busy.reject')}
                pending={words.text('reject.pending')}
                busy={busy === 'reject'}
                onConfirm={() => onAct('reject', { reason }, dismiss)}
                onDismiss={dismiss}
            >
                <Field id="reject-reason" label={words.text('reject.reason')} help={words.text('reject.reason_help')}>
                    {(control) => <Textarea {...control} className="admin-reject-prompt__reason" rows={3} value={reason} onChange={(event) => setReason(event.target.value)} />}
                </Field>
            </Confirm>
            <Confirm
                open={prompt === 'cancel'}
                title={words.text('cancel.title')}
                name="admin-cancel-prompt"
                confirmLabel={words.text('actions.cancel')}
                busyLabel={words.text('busy.cancel')}
                pending={words.text('cancel.pending')}
                busy={busy === 'cancel'}
                onConfirm={() => onAct('cancel', {}, dismiss)}
                onDismiss={dismiss}
            >
                <p className="admin-cancel-prompt__warning">{words.text('cancel.warning')}</p>
            </Confirm>
        </div>
    );
}
