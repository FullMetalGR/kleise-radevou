import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Button, type ButtonVariant } from './button';
import { mount, press, type Mounted } from './harness';

/*
 * The button: four variants with secondary the default, a destructive flag orthogonal to them,
 * and a busy state that shows a spinner before the label, sets aria-busy and disables the
 * control, and the busy label is the text stating why it is disabled, required wherever busy is
 * written. A press on a busy or disabled button fires nothing.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const button = () => view.host.querySelector<HTMLButtonElement>('button');

describe('the button', () => {
    it('renders each of the four variants under a class of its own, secondary by default', () => {
        const seen = new Set<string>();

        for (const variant of ['primary', 'secondary', 'tertiary', 'link'] satisfies ButtonVariant[]) {
            view.render(<Button variant={variant}>Save</Button>);
            expect(button()?.classList.contains(`admin-button--${variant}`)).toBe(true);
            seen.add(button()?.className ?? '');
        }

        expect(seen.size).toBe(4);

        view.render(<Button>Save</Button>);
        expect(button()?.classList.contains('admin-button--secondary')).toBe(true);
        expect(button()?.getAttribute('type')).toBe('button');
    });

    it('carries the destructive flag beside any variant, and not without it', () => {
        view.render(
            <Button variant="primary" destructive>
                Delete
            </Button>,
        );
        expect(button()?.classList.contains('admin-button--primary')).toBe(true);
        expect(button()?.classList.contains('admin-button--destructive')).toBe(true);

        view.render(<Button variant="link">Delete</Button>);
        expect(button()?.classList.contains('admin-button--destructive')).toBe(false);
    });

    it('is normal with no spinner, no aria-busy and enabled', () => {
        view.render(<Button>Save</Button>);

        expect(button()?.disabled).toBe(false);
        expect(button()?.hasAttribute('aria-busy')).toBe(false);
        expect(view.host.querySelector('.admin-button__spinner')).toBeNull();
        expect(button()?.textContent).toBe('Save');
    });

    it('when busy shows the spinner before the busy label, sets aria-busy and disables', () => {
        view.render(
            <Button busy busyLabel="Saving...">
                Save
            </Button>,
        );

        const spinner = view.host.querySelector('.admin-button__spinner');

        expect(button()?.getAttribute('aria-busy')).toBe('true');
        expect(button()?.disabled).toBe(true);
        expect(spinner).not.toBeNull();
        expect(spinner?.getAttribute('aria-hidden')).toBe('true');
        expect(button()?.firstElementChild).toBe(spinner);
        expect(button()?.textContent).toBe('Saving...');
    });

    it('states why it is disabled while busy: the busy label is the reason in text, and a busy button cannot be written without one', () => {
        view.render(
            <Button busy={false} busyLabel="Saving...">
                Save
            </Button>,
        );
        expect(button()?.textContent).toBe('Save');

        view.render(
            <Button busy busyLabel="Saving...">
                Save
            </Button>,
        );
        expect(button()?.disabled).toBe(true);
        expect(button()?.textContent).toBe('Saving...');

        // @ts-expect-error a busy button states its reason, so the busy label is required with busy
        const unstated = <Button busy>Save</Button>;

        expect(unstated.props.busy).toBe(true);
    });

    it('fires nothing on a press while busy or disabled, and fires once when normal', () => {
        const onClick = vi.fn();

        view.render(
            <Button busy busyLabel="Saving..." onClick={onClick}>
                Save
            </Button>,
        );
        press(button());
        view.render(
            <Button disabled onClick={onClick}>
                Save
            </Button>,
        );
        press(button());
        expect(onClick).not.toHaveBeenCalled();

        view.render(<Button onClick={onClick}>Save</Button>);
        press(button());
        expect(onClick).toHaveBeenCalledOnce();
    });
});
