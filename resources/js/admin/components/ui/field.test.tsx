import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Field, HelpLine } from './field';
import { mount, type Mounted } from './harness';
import { TextInput } from './input';

/*
 * The field shell: a label pointing at the control by id and wrapping nothing, an optional line
 * underneath hung off aria-describedby, and an error that replaces the help line, is referenced
 * in its place and sets aria-invalid on the control.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const input = () => view.host.querySelector('input');
const label = () => view.host.querySelector('label');

describe('the field shell', () => {
    it('points the label at the control by id and wraps nothing in it', () => {
        view.render(<Field id="service-name" label="Name">{(control) => <TextInput {...control} />}</Field>);

        expect(label()?.getAttribute('for')).toBe('service-name');
        expect(input()?.id).toBe('service-name');
        expect(label()?.children).toHaveLength(0);
        expect(label()?.textContent).toBe('Name');
        expect(input()?.hasAttribute('aria-describedby')).toBe(false);
        expect(input()?.hasAttribute('aria-invalid')).toBe(false);
    });

    it('references the help line through aria-describedby', () => {
        view.render(
            <Field id="service-name" label="Name" help="Shown to customers.">
                {(control) => <TextInput {...control} />}
            </Field>,
        );

        const described = input()?.getAttribute('aria-describedby') ?? '';

        expect(document.getElementById(described)?.textContent).toBe('Shown to customers.');
        expect(input()?.hasAttribute('aria-invalid')).toBe(false);
    });

    it('draws a compact field\'s label and line small, the line still describing the control, and any field\'s help line at 12px', () => {
        view.render(
            <Field id="colour-by" label="Colour by" help="Only a manager changes it." compact={true}>
                {(control) => <TextInput {...control} />}
            </Field>,
        );

        const line = document.getElementById(input()?.getAttribute('aria-describedby') ?? '');

        expect(label()?.classList.contains('text-xs')).toBe(true);
        expect(label()?.classList.contains('text-sm')).toBe(false);
        expect(line?.textContent).toBe('Only a manager changes it.');
        expect(line?.classList.contains('text-xs')).toBe(true);

        view.render(
            <Field id="colour-by" label="Colour by" help="Only a manager changes it.">
                {(control) => <TextInput {...control} />}
            </Field>,
        );

        expect(label()?.classList.contains('text-sm')).toBe(true);
        // The help line is 12px in every field.
        expect(document.getElementById(input()?.getAttribute('aria-describedby') ?? '')?.classList.contains('text-xs')).toBe(true);
        expect(document.getElementById(input()?.getAttribute('aria-describedby') ?? '')?.classList.contains('text-sm')).toBe(false);
    });

    it('replaces the help line with the error, references the error in its place and marks the control invalid', () => {
        view.render(
            <Field id="service-name" label="Name" help="Shown to customers." error="The name is required.">
                {(control) => <TextInput {...control} />}
            </Field>,
        );

        const described = input()?.getAttribute('aria-describedby') ?? '';

        expect(document.getElementById(described)?.textContent).toBe('The name is required.');
        expect(view.host.textContent).not.toContain('Shown to customers.');
        expect(view.host.querySelector('.admin-field__help')).toBeNull();
        expect(view.host.querySelector('.admin-field__error')?.id).toBe(described);
        expect(input()?.getAttribute('aria-invalid')).toBe('true');
    });

    it('treats a blank error as none', () => {
        view.render(
            <Field id="service-name" label="Name" help="Shown to customers." error="">
                {(control) => <TextInput {...control} />}
            </Field>,
        );

        expect(view.host.querySelector('.admin-field__help')?.textContent).toBe('Shown to customers.');
        expect(input()?.hasAttribute('aria-invalid')).toBe(false);
    });
});

describe('the help line on its own', () => {
    it('draws a line as the field draws its help, under the caller\'s own class, and a status line keeps its role', () => {
        view.render(
            <>
                <Field id="service-name" label="Name" help="Shown to customers.">
                    {(control) => <TextInput {...control} />}
                </Field>
                <HelpLine className="admin-card__help">Explains the card.</HelpLine>
                <HelpLine className="admin-card__wait" role="status">
                    Saving...
                </HelpLine>
            </>,
        );
        const field = view.host.querySelector('.admin-field .admin-field__help');
        const help = view.host.querySelector('.admin-card__help');
        const wait = view.host.querySelector('.admin-card__wait');

        expect(help?.className).toBe(`${field?.className} admin-card__help`);
        expect(help?.textContent).toBe('Explains the card.');
        expect(help?.hasAttribute('role')).toBe(false);
        expect(wait?.getAttribute('role')).toBe('status');
    });
});
