import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { ENGLISH_WORDS, GREEK_WORDS, keyed, said } from '../bookingWords';
import { TRANSPORT_SENTENCE, type Notice } from './api';
import { DetailsStep, EMAIL_FIELD_LABEL, NAME_FIELD_LABEL, NOTE_COUNT, NOTE_LABEL, PHONE_FIELD_LABEL } from './DetailsStep';
import { BLOCKED_SENTENCE, NOTE_MAX } from './hold';
import { EMPTY_DRAFT, type CustomerDraft } from './hold';
import { BOOKING_LINES_EN } from './lines.generated';
import type { Words } from './words';

/*
 * Step details: a real form, so Enter submits and the browser's own constraint validation
 * applies; three labelled fields in order with their autocomplete values; no home-grown
 * validator; no submit button of its own, the summary card's Continue submitting it through the
 * form attribute, which the harness stands in for beside it; the fields enabled whatever is in
 * flight; and the server's refusal rendered politely beside the form with everything typed kept.
 * Rendered with the keyed table, and once with the English file.
 */

const FORM_ID = 'the-details-form';

const TYPED: CustomerDraft = { name: 'Casey Customer', email: 'casey@example.com', phone: '+30 210 0000000', note: '' };

const REFUSAL: Notice = { text: 'refusal-token', tone: 'polite' };

const DROPPED: Notice = { key: TRANSPORT_SENTENCE, tone: 'alert' };

let container: HTMLDivElement;
let root: Root;
let onChange: Mock<(field: keyof CustomerDraft, value: string) => void>;
let onSubmit: Mock<() => void>;

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    onChange = vi.fn<(field: keyof CustomerDraft, value: string) => void>();
    onSubmit = vi.fn<() => void>();
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

interface Overrides {
    readonly draft?: CustomerDraft;
    readonly notice?: Notice | null;
    /** Whether the stand-in for the summary card's Continue is disabled, as it is while a hold flies. */
    readonly busy?: boolean;
}

/** The step, and beside it outside the form the stand-in for the summary card's Continue. */
async function render(overrides: Overrides = {}, words?: Words): Promise<void> {
    await act(() => {
        root.render(
            keyed(
                <>
                    <DetailsStep formId={FORM_ID} draft={overrides.draft ?? EMPTY_DRAFT} notice={overrides.notice ?? null} onChange={onChange} onSubmit={onSubmit} />
                    <button type="submit" form={FORM_ID} disabled={overrides.busy ?? false}>
                        Continue
                    </button>
                </>,
                words,
            ),
        );
    });
}

function form(): HTMLFormElement {
    return container.querySelector('form') as HTMLFormElement;
}

function fields(): HTMLInputElement[] {
    return Array.from(container.querySelectorAll('input'));
}

function field(name: string): HTMLInputElement {
    return container.querySelector(`input[name="${name}"]`) as HTMLInputElement;
}

function labelOf(input: HTMLInputElement): string {
    return container.querySelector(`label[for="${input.id}"]`)?.textContent ?? '';
}

function submit(): HTMLButtonElement {
    return container.querySelector('button[type="submit"]') as HTMLButtonElement;
}

async function press(el: HTMLElement): Promise<void> {
    await act(() => {
        el.click();
    });
}

/**
 * A keystroke as the browser delivers one: the value written through the prototype's own
 * setter, which is what gets past the value tracker React installs on the node itself, and
 * then the input event React listens for.
 */
async function type(input: HTMLInputElement, value: string): Promise<void> {
    await act(() => {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

describe('the form', () => {
    it('is a real form holding the three fields in order, each labelled, with its autocomplete value', async () => {
        await render();

        expect(form()).not.toBeNull();
        expect(fields().map((input) => input.name)).toEqual(['customer_name', 'customer_email', 'customer_phone']);
        expect(fields().map((input) => input.getAttribute('autocomplete'))).toEqual(['name', 'email', 'tel']);
        expect(fields().map((input) => input.type)).toEqual(['text', 'email', 'tel']);
        expect(fields().map((input) => input.required)).toEqual([true, true, false]);
        expect(fields().map((input) => labelOf(input))).toEqual([said(NAME_FIELD_LABEL), said(EMAIL_FIELD_LABEL), said(PHONE_FIELD_LABEL)]);
        expect(fields().every((input) => input.id !== '' && labelOf(input) !== '')).toBe(true);
    });

    it('submits on Enter, which is what a real form with a submit button is: requesting the submit reports it', async () => {
        await render({ draft: TYPED });

        expect(submit().type).toBe('submit');

        await act(() => {
            form().requestSubmit();
        });

        expect(onSubmit).toHaveBeenCalledTimes(1);
    });

    it('holds no submit button of its own and carries the id the summary card\'s Continue names it by, so that Continue is its submit button', async () => {
        await render({ draft: TYPED });

        expect(form().id).toBe(FORM_ID);
        expect(form().querySelector('button')).toBeNull();
        expect(submit().form).toBe(form());

        await press(submit());

        expect(onSubmit).toHaveBeenCalledTimes(1);
    });

    it('carries no validation of its own: the browser stops an empty required field and nothing is reported', async () => {
        await render();

        await press(submit());

        expect(onSubmit).not.toHaveBeenCalled();
        expect(form().checkValidity()).toBe(false);
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(container.querySelector('[role="status"]')?.textContent).toBe('');
    });

    it('stops an address that is not one, and reports the submit once every required field holds a value', async () => {
        await render({ draft: { ...TYPED, email: 'casey at example.com' } });

        await press(submit());

        expect(onSubmit).not.toHaveBeenCalled();

        await render({ draft: { ...TYPED, phone: '' } });
        await press(submit());

        expect(onSubmit).toHaveBeenCalledTimes(1);
    });

    it('renders the draft the flow owns and reports every keystroke by field, holding nothing itself', async () => {
        await render({ draft: TYPED });

        expect(fields().map((input) => input.value)).toEqual(['Casey Customer', 'casey@example.com', '+30 210 0000000']);

        await type(field('customer_name'), 'Casey C');
        await type(field('customer_email'), 'casey@example.org');
        await type(field('customer_phone'), '');

        expect(onChange.mock.calls).toEqual([
            ['name', 'Casey C'],
            ['email', 'casey@example.org'],
            ['phone', ''],
        ]);
    });
});

describe('the note', () => {
    function note(): HTMLTextAreaElement {
        return container.querySelector('textarea[name="note"]') as HTMLTextAreaElement;
    }

    it('follows Phone as an optional labelled textarea of up to five hundred characters, with its count tied to it', async () => {
        await render({ draft: TYPED });

        const controls = Array.from(container.querySelectorAll('input, textarea')).map((control) => control.getAttribute('name'));
        const count = container.querySelector('.kr-booking__count');

        expect(controls).toEqual(['customer_name', 'customer_email', 'customer_phone', 'note']);
        expect(container.querySelector(`label[for="${note().id}"]`)?.textContent).toBe(said(NOTE_LABEL));
        expect(note().required).toBe(false);
        expect(note().maxLength).toBe(NOTE_MAX);
        expect(note().className).toContain('kr-booking__input');
        expect(count?.textContent).toBe(said(NOTE_COUNT, { count: '0', n: String(NOTE_MAX) }));
        expect(note().getAttribute('aria-describedby')).toBe(count?.id);
        expect(count?.id).not.toBe('');
    });

    it('reads the English file\'s labels, the note\'s and the three fields\', and its count', async () => {
        await render({}, ENGLISH_WORDS);

        expect(container.querySelector(`label[for="${note().id}"]`)?.textContent).toBe(BOOKING_LINES_EN.details.note);
        expect(fields().map((input) => labelOf(input))).toEqual([BOOKING_LINES_EN.details.name, BOOKING_LINES_EN.details.email, BOOKING_LINES_EN.details.phone]);
        expect(container.querySelector('.kr-booking__count')?.textContent).toBe(BOOKING_LINES_EN.details.count.replace(':count', '0').replace(':n', String(NOTE_MAX)));
    });

    it('renders the note the flow owns and counts it in characters, reporting each keystroke as the note', async () => {
        await render({ draft: { ...TYPED, note: 'Allergic to lavender.' } });

        expect(note().value).toBe('Allergic to lavender.');
        expect(container.querySelector('.kr-booking__count')?.textContent).toBe(said(NOTE_COUNT, { count: '21', n: String(NOTE_MAX) }));

        await act(() => {
            Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set?.call(note(), 'Quiet room, please.');
            note().dispatchEvent(new Event('input', { bubbles: true }));
        });

        expect(onChange.mock.calls).toEqual([['note', 'Quiet room, please.']]);
    });

    it('counts a character beyond the basic plane as one, as the server does', async () => {
        await render({ draft: { ...TYPED, note: 'cafe \u{1F600}' } });

        expect(container.querySelector('.kr-booking__count')?.textContent).toBe(said(NOTE_COUNT, { count: '6', n: String(NOTE_MAX) }));
    });

    it('stays enabled while the hold flies, as every field does', async () => {
        await render({ draft: TYPED, busy: true });

        expect(note().disabled).toBe(false);
    });
});

describe('while the hold flies', () => {
    it('leaves every field enabled, whatever the summary card\'s Continue says', async () => {
        await render({ draft: TYPED, busy: true });

        expect(fields().map((input) => input.disabled)).toEqual([false, false, false]);
        expect(fields().map((input) => input.readOnly)).toEqual([false, false, false]);
    });

    it('reports nothing on a press of the summary card\'s Continue while it is disabled', async () => {
        await render({ draft: TYPED, busy: true });

        await press(submit());

        expect(onSubmit).not.toHaveBeenCalled();
    });
});

describe('the refusal', () => {
    it('renders the server sentence politely beside the form, with everything typed kept', async () => {
        await render({ draft: TYPED, notice: REFUSAL });

        expect(container.querySelector('[role="status"]')?.textContent).toBe(REFUSAL.text);
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(fields().map((input) => input.value)).toEqual(['Casey Customer', 'casey@example.com', '+30 210 0000000']);
    });

    it('renders a transport failure as an alert carrying the fixed sentence, never the browser message', async () => {
        await render({ draft: TYPED, notice: DROPPED });

        expect(container.querySelector('[role="alert"]')?.textContent).toBe(said(TRANSPORT_SENTENCE));
        expect(container.querySelector('[role="status"]')?.textContent).toBe('');
        expect(container.textContent).not.toContain('Failed to fetch');
        expect(fields().map((input) => input.value)).toEqual(['Casey Customer', 'casey@example.com', '+30 210 0000000']);
    });

    it('renders a blocked address\'s refusal as the client\'s own sentence, in English and in Greek', async () => {
        await render({ draft: TYPED, notice: { key: BLOCKED_SENTENCE, tone: 'polite' } }, ENGLISH_WORDS);

        expect(container.querySelector('[role="status"]')?.textContent).toBe(ENGLISH_WORDS.text(BLOCKED_SENTENCE));
        expect(ENGLISH_WORDS.text(BLOCKED_SENTENCE)).toBe(BOOKING_LINES_EN.details.blocked);

        await render({ draft: TYPED, notice: { key: BLOCKED_SENTENCE, tone: 'polite' } }, GREEK_WORDS);

        expect(container.querySelector('[role="status"]')?.textContent).toBe(GREEK_WORDS.text(BLOCKED_SENTENCE));
        expect(GREEK_WORDS.text(BLOCKED_SENTENCE)).not.toBe(ENGLISH_WORDS.text(BLOCKED_SENTENCE));
    });

    it('keeps the notice region outside the form, so no keystroke re-announces the panel', async () => {
        await render({ draft: TYPED, notice: REFUSAL });

        const region = container.querySelector('[role="status"]') as HTMLElement;

        expect(form().contains(region)).toBe(false);
        expect(region.tagName).toBe('P');
    });
});
