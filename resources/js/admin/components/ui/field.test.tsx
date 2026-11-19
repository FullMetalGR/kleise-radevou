import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ErrorLine, Field, FieldFrame, HelpLine } from './field';
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

    it('draws a compact field\'s label and help small, any other label and help at 13.5px, both in the muted ink', () => {
        view.render(
            <Field id="colour-by" label="Colour by" help="Only a manager changes it." compact={true}>
                {(control) => <TextInput {...control} />}
            </Field>,
        );

        const line = () => document.getElementById(input()?.getAttribute('aria-describedby') ?? '');

        expect(label()?.classList.contains('text-xs')).toBe(true);
        expect(label()?.classList.contains('text-label')).toBe(false);
        expect(line()?.textContent).toBe('Only a manager changes it.');
        // A compact field's help is 12px beside its 12px label and error, at the body weight.
        expect(line()?.className).toBe('admin-field__help text-xs font-medium text-muted-foreground');

        view.render(
            <Field id="colour-by" label="Colour by" help="Only a manager changes it.">
                {(control) => <TextInput {...control} />}
            </Field>,
        );

        expect(label()?.classList.contains('text-label')).toBe(true);
        expect(label()?.classList.contains('text-muted-foreground')).toBe(true);
        expect(label()?.classList.contains('text-xs')).toBe(false);
        // Any other field's help is 13.5px at the body weight.
        expect(line()?.className).toBe('admin-field__help text-label font-medium text-muted-foreground');
    });

    it('starts the error with an alert icon kept from a screen reader, so the error never rests on colour alone', () => {
        view.render(
            <Field id="service-name" label="Name" error="The name is required.">
                {(control) => <TextInput {...control} />}
            </Field>,
        );

        const error = view.host.querySelector('.admin-field__error');
        const icon = error?.querySelector('svg');

        expect(icon?.getAttribute('aria-hidden')).toBe('true');
        expect(error?.firstElementChild?.contains(icon ?? null)).toBe(true);
        expect(error?.textContent).toBe('The name is required.');
        expect(error?.classList.contains('text-destructive')).toBe(true);
        expect(error?.classList.contains('font-semibold')).toBe(true);
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

describe('the error line on its own', () => {
    it('draws a line as the field draws its error, alert icon first, under the id its group points at', () => {
        view.render(
            <>
                <Field id="service-name" label="Name" error="The name is required.">
                    {(control) => <TextInput {...control} />}
                </Field>
                <ErrorLine id="services-error">Choose a service from the list.</ErrorLine>
            </>,
        );
        const field = view.host.querySelector('#service-name-error');
        const line = view.host.querySelector('#services-error');

        expect(line?.tagName.toLowerCase()).toBe('p');
        expect(line?.className).toBe(field?.className);
        expect(line?.classList.contains('admin-field__error')).toBe(true);
        expect(line?.classList.contains('text-label')).toBe(true);
        expect(line?.firstElementChild?.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
        expect(line?.textContent).toBe('Choose a service from the list.');
        expect(line?.hasAttribute('role')).toBe(false);
    });
});

describe('the frame around something that is no control', () => {
    it('draws the frame and the label as the field draws its own, the label plain text pointing at nothing', () => {
        view.render(
            <>
                <Field id="move-time" label="Time">
                    {(control) => <TextInput {...control} />}
                </Field>
                <FieldFrame label="Time">
                    <p className="waiting">Loading the times...</p>
                </FieldFrame>
            </>,
        );
        const [field, frame] = [...view.host.querySelectorAll('.admin-field')];
        const plain = frame?.querySelector('.admin-field__label');

        expect(frame?.className).toBe(field?.className);
        expect(plain?.className).toBe(label()?.className);
        expect(plain?.tagName.toLowerCase()).toBe('span');
        expect(plain?.textContent).toBe('Time');
        expect(frame?.querySelector('.waiting')?.textContent).toBe('Loading the times...');
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
