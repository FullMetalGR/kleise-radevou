import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Confirm } from '@/components/ui/confirm';
import { Field } from '@/components/ui/field';
import { Textarea } from '@/components/ui/input';
import { formatInstant, type TenantClock } from '@/lib/format';
import { useOneWrite } from '@/lib/one-write';
import type { Note } from './model';
import { type CustomersKey, useCustomers } from './words';

/*
 * A customer's Notes card: New note, a textarea with its help line "Seen by the staff only.", and
 * Add note, which posts the text as typed to the customer's notes route, POST {page}/notes; the
 * field empties once the server has taken it. Beneath, the notes as the server sent them, newest
 * first, each its text, then its author's name with its day and time in the tenant's zone and
 * format, and Remove, which asks through the kit's confirm before it deletes the note by its id,
 * DELETE {page}/notes/{id}. A refusal is the server's sentence in an error toast with what was
 * typed kept; a write that never reached it is the card's own sentence. Nothing is optimistic:
 * the list reads the page's notes prop, which the redirect re-reads. Every word is a key of the
 * customers section.
 */

const ADD = 'notes.add' satisfies CustomersKey;

const ADDING = 'notes.adding' satisfies CustomersKey;

const REMOVING = 'notes.removing' satisfies CustomersKey;

type Props = { url: string; notes: readonly Note[]; clock: TenantClock };

export function CustomerNotesCard({ url, notes, clock }: Props) {
    const words = useCustomers();
    const write = useOneWrite({ add: words.text(ADDING), remove: words.text(REMOVING) });
    const [body, setBody] = useState('');
    const [removing, setRemoving] = useState<number | null>(null);

    const add = () =>
        write.visit(
            'add',
            (failure) => failure.sentence ?? words.text('notes.add_error'),
            () => setBody(''),
            (options) => router.post(`${url}/notes`, { body }, options),
        );

    const remove = (id: number) =>
        write.visit(
            'remove',
            (failure) => failure.sentence ?? words.text('notes.remove_error'),
            () => setRemoving(null),
            (options) => router.delete(`${url}/notes/${id}`, options),
        );

    return (
        <section className="admin-card admin-customer-notes flex flex-col gap-3 rounded-xl border p-4 text-sm" aria-labelledby="customer-notes-head">
            <h2 id="customer-notes-head">
                {words.text('notes.title')}
            </h2>
            <Field id="customer-note" label={words.text('notes.new')} help={words.text('notes.help')}>
                {(control) => <Textarea {...control} className="min-h-18" value={body} onChange={(event) => setBody(event.target.value)} />}
            </Field>
            <div>
                <Button className="admin-customer-notes__add" busy={write.inFlight === 'add'} busyLabel={words.text(ADDING)} onClick={add}>
                    {words.text(ADD)}
                </Button>
            </div>
            {notes.length > 0 && (
                <ul className="admin-customer-notes__list flex flex-col gap-2">
                    {notes.map((note) => (
                        <li key={note.id} className="admin-customer-note flex flex-col gap-1 rounded-[10px] border p-3">
                            <p className="admin-customer-note__body whitespace-pre-line break-words">{note.body}</p>
                            <div className="flex justify-between gap-3">
                                <span className="admin-customer-note__byline text-xs text-muted-foreground">{words.text('notes.byline', { author: note.author, when: formatInstant(note.created_at, clock) })}</span>
                                <Button
                                    variant="link"
                                    destructive
                                    className="admin-customer-note__remove"
                                    aria-label={words.text('notes.remove_label', { author: note.author, when: formatInstant(note.created_at, clock) })}
                                    onClick={() => setRemoving(note.id)}
                                >
                                    {words.text('notes.remove')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <Confirm
                open={removing !== null}
                title={words.text('notes.confirm_title')}
                name="admin-note-remove"
                confirmLabel={words.text('notes.confirm')}
                busyLabel={words.text(REMOVING)}
                pending={words.text('notes.pending')}
                busy={write.inFlight === 'remove'}
                onConfirm={() => removing !== null && remove(removing)}
                onDismiss={() => setRemoving(null)}
            >
                <p className="admin-note-remove__body">{words.text('notes.confirm_body')}</p>
            </Confirm>
        </section>
    );
}
