import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { Textarea, TextInput } from './input';

/*
 * Text and textarea: the date, time, datetime and number types render in the monospace face so
 * figures line up with table columns; the rest in the body face.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('the text input and the textarea', () => {
    it('stands 32px high as a single line, the control height, and leaves the textarea its own', () => {
        view.render(<TextInput />);
        expect(view.host.querySelector('input')?.classList.contains('h-8')).toBe(true);

        view.render(<Textarea rows={3} />);
        expect(view.host.querySelector('textarea')?.classList.contains('h-8')).toBe(false);
    });

    it('sets the date, time, datetime and number types in the monospace face', () => {
        for (const type of ['date', 'time', 'datetime-local', 'number'] as const) {
            view.render(<TextInput type={type} />);

            const input = view.host.querySelector('input');

            expect(input?.getAttribute('type')).toBe(type);
            expect(input?.classList.contains('admin-input--figures')).toBe(true);
            expect(input?.classList.contains('font-mono')).toBe(true);
        }
    });

    it('sets text, email and password in the body face, text by default', () => {
        for (const type of ['text', 'email', 'password'] as const) {
            view.render(<TextInput type={type} />);
            expect(view.host.querySelector('input')?.classList.contains('font-mono')).toBe(false);
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
