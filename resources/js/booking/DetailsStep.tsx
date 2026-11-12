import { useId, type FormEvent, type ReactNode } from 'react';
import type { Notice } from './api';
import { type CustomerDraft, NOTE_MAX } from './hold';
import { NoticeRegion } from './NoticeRegion';
import { useWords, type LineKey } from './words';

/*
 * Step details. A real form, so Enter submits and the browser's own constraint validation
 * applies; three labelled fields in order, each carrying the right autocomplete value; then the
 * optional note, "Anything we should know?", a textarea held to five hundred characters by its
 * native maxlength with its count beside it, for the business's eyes and never echoed back; and
 * no validator of our own. The server is the authority on what a valid customer is, and its
 * refusal sentence renders verbatim beside the form.
 *
 * The draft belongs to the flow, not to this component: a 409 unmounts the form, and a
 * customer who has just been told their slot was taken is the last person who should be asked
 * to type their email again (1.5). Every keystroke is reported by field and nothing is held
 * here.
 *
 * The form holds no submit button of its own. Its submit button is the summary card's Continue,
 * which names the form by the id the flow hands both through the form attribute, so it is the
 * form's default button: Enter in a field submits through it, and its press runs the browser's
 * constraint validation first, as a button inside would. That Continue reads the busy label and
 * disables while the hold flies; the input fields here stay enabled, so a customer can keep
 * correcting while a request is out. The disabled attribute is the visible affordance only; the
 * guard that stops a second hold is the flow's synchronous latch.
 *
 * The notice region sits beside the form and not inside it, because a live region on a
 * container holding a form is re-announced on every keystroke (7.3).
 */

interface DetailsStepProps {
    /** The form's id, which the summary card's Continue names in its form attribute. */
    readonly formId: string;
    readonly draft: CustomerDraft;
    readonly notice: Notice | null;
    readonly onChange: (field: keyof CustomerDraft, value: string) => void;
    readonly onSubmit: () => void;
}

/** What the summary card's Continue reads while the hold flies, disabled. */
export const BUSY_LABEL: LineKey = 'details.busy';

/** The note's label. */
export const NOTE_LABEL: LineKey = 'details.note';

/** The three fields' labels. */
export const NAME_FIELD_LABEL: LineKey = 'details.name';

export const EMAIL_FIELD_LABEL: LineKey = 'details.email';

export const PHONE_FIELD_LABEL: LineKey = 'details.phone';

/**
 * The note's count line under it, in characters as the server counts them, its :count, and the
 * most the note holds, its :n.
 */
export const NOTE_COUNT: LineKey = 'details.count';

export function DetailsStep({ formId, draft, notice, onChange, onSubmit }: DetailsStepProps): ReactNode {
    const { text } = useWords();
    const nameId = useId();
    const emailId = useId();
    const phoneId = useId();
    const noteId = useId();
    const countId = useId();

    function handleSubmit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        onSubmit();
    }

    return (
        <div className="kr-booking__step">
            <NoticeRegion notice={notice} />
            <form className="kr-booking__form" id={formId} onSubmit={handleSubmit}>
                <Field id={nameId} name="customer_name" label={NAME_FIELD_LABEL} type="text" autoComplete="name" required value={draft.name} onChange={(value) => onChange('name', value)} />
                <Field id={emailId} name="customer_email" label={EMAIL_FIELD_LABEL} type="email" autoComplete="email" required value={draft.email} onChange={(value) => onChange('email', value)} />
                <Field id={phoneId} name="customer_phone" label={PHONE_FIELD_LABEL} type="tel" autoComplete="tel" required={false} value={draft.phone} onChange={(value) => onChange('phone', value)} />
                <div className="kr-booking__field">
                    <label className="kr-booking__label" htmlFor={noteId}>
                        {text(NOTE_LABEL)}
                    </label>
                    <textarea className="kr-booking__input kr-booking__note" id={noteId} name="note" rows={3} maxLength={NOTE_MAX} aria-describedby={countId} value={draft.note} onChange={(event) => onChange('note', event.target.value)} />
                    <span className="kr-booking__count" id={countId}>
                        {text(NOTE_COUNT, { count: String(Array.from(draft.note).length), n: String(NOTE_MAX) })}
                    </span>
                </div>
            </form>
        </div>
    );
}

interface FieldProps {
    readonly id: string;
    readonly name: string;
    readonly label: LineKey;
    readonly type: 'text' | 'email' | 'tel';
    readonly autoComplete: string;
    readonly required: boolean;
    readonly value: string;
    readonly onChange: (value: string) => void;
}

function Field({ id, name, label, type, autoComplete, required, value, onChange }: FieldProps): ReactNode {
    const { text } = useWords();

    return (
        <div className="kr-booking__field">
            <label className="kr-booking__label" htmlFor={id}>
                {text(label)}
            </label>
            <input
                className="kr-booking__input"
                id={id}
                name={name}
                type={type}
                autoComplete={autoComplete}
                required={required}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}
