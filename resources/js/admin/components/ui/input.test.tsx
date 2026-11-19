import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { Textarea, TextInput } from './input';

/*
 * Text and textarea: a 46px field on the card fill inside a 1.5px ring, the ring darker under the
 * pointer and the focus outline on the keyboard. The date, time, datetime and number types set
 * their figures in fixed-width numerals so they line up with table columns, in the body face.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('the text input and the textarea', () => {
    it('stands 46px high as a single line and leaves the textarea its own height and padding', () => {
        view.render(<TextInput />);

        const input = view.host.querySelector('input');

        expect(input?.classList.contains('h-11.5')).toBe(true);
        for (const look of ['rounded-field', 'bg-card', 'px-3.5', 'text-body', 'inset-ring-[1.5px]', 'inset-ring-border', 'focus-ring-field', 'placeholder:text-ink-tertiary']) {
            expect(input?.classList.contains(look)).toBe(true);
        }
        expect(input?.className).not.toContain('focus-visible:ring');

        view.render(<Textarea rows={3} />);

        const area = view.host.querySelector('textarea');

        expect(area?.classList.contains('h-11.5')).toBe(false);
        expect(area?.classList.contains('min-h-20')).toBe(true);
        expect(area?.classList.contains('py-3')).toBe(true);
    });

    it('darkens the ring under the pointer only on a control that can be typed in', () => {
        view.render(<TextInput />);

        const hovers = [...(view.host.querySelector('input')?.classList ?? [])].filter((name) => name.includes('hover:'));

        // Gated on a field that is enabled as well as one that is not read-only, so a disabled field
        // is held off in its own right.
        expect(hovers).toEqual(['enabled:not-read-only:hover:not-aria-invalid:inset-ring-ink-tertiary']);
    });

    it('dims a disabled control on the soft fill, with no hover ring and the not-allowed pointer', () => {
        view.render(<TextInput disabled={true} />);

        const input = view.host.querySelector('input');

        expect(input?.disabled).toBe(true);
        expect(input?.classList.contains('disabled:opacity-45')).toBe(true);
        expect(input?.classList.contains('disabled:bg-muted')).toBe(true);
        expect(input?.classList.contains('disabled:cursor-not-allowed')).toBe(true);
        // The hover ring is gated on an enabled field, and a disabled field is read-only to the
        // browser besides, so the ring never reaches it.
        expect(input?.matches(':enabled')).toBe(false);
        expect(input?.matches(':read-only')).toBe(true);
        expect([...(input?.classList ?? [])].filter((name) => name.includes('hover:')).every((name) => name.startsWith('enabled:'))).toBe(true);
    });

    it('sets a read-only control on the soft fill, with no hover ring', () => {
        view.render(<Textarea readOnly={true} defaultValue="<script></script>" />);

        const area = view.host.querySelector('textarea');

        expect(area?.readOnly).toBe(true);
        expect(area?.classList.contains('read-only:bg-muted')).toBe(true);
        expect(area?.matches(':read-only')).toBe(true);
    });

    it('rings an invalid control in 2px of the destructive colour and announces it invalid', () => {
        view.render(<TextInput aria-invalid={true} />);

        const input = view.host.querySelector('input');

        expect(input?.getAttribute('aria-invalid')).toBe('true');
        expect(input?.classList.contains('aria-invalid:inset-ring-2')).toBe(true);
        expect(input?.classList.contains('aria-invalid:inset-ring-destructive')).toBe(true);
    });

    it('sets the date, time, datetime and number types in fixed-width numerals of the body face', () => {
        for (const type of ['date', 'time', 'datetime-local', 'number'] as const) {
            view.render(<TextInput type={type} />);

            const input = view.host.querySelector('input');

            expect(input?.getAttribute('type')).toBe(type);
            expect(input?.classList.contains('admin-input--figures')).toBe(true);
            expect(input?.classList.contains('tabular-nums')).toBe(true);
            expect(input?.classList.contains('font-mono')).toBe(false);
        }
    });

    it('sets text, email and password in the body face, text by default', () => {
        for (const type of ['text', 'email', 'password'] as const) {
            view.render(<TextInput type={type} />);
            expect(view.host.querySelector('input')?.classList.contains('font-mono')).toBe(false);
            expect(view.host.querySelector('input')?.classList.contains('admin-input--figures')).toBe(false);
        }

        view.render(<TextInput />);
        expect(view.host.querySelector('input')?.getAttribute('type')).toBe('text');
        expect(view.host.querySelector('input')?.classList.contains('admin-input')).toBe(true);
    });

    it('renders a textarea under the same base class', () => {
        view.render(<Textarea rows={3} defaultValue="A note" />);

        expect(view.host.querySelector('textarea')?.classList.contains('admin-input')).toBe(true);
        expect(view.host.querySelector('textarea')?.value).toBe('A note');
    });
});
