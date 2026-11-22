import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Button, buttonLook, type ButtonVariant, REACH } from './button';
import { mount, press, type Mounted } from './harness';
import { FORCED_EDGE } from './input';

/*
 * The button: four variants with secondary the default, a destructive flag orthogonal to them,
 * three heights, an icon before the label or in its place, a long label let to wrap below the
 * breakpoint where the button is told to, and a busy state that shows a spinner before the label,
 * sets aria-busy and disables the control, and the busy label is the text stating why it is
 * disabled, required wherever busy is written. A press on a busy or disabled button fires
 * nothing. The look is read by class: hover, press and focus are states a component test cannot
 * enter, and the browser suite runs with motion off.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const button = () => view.host.querySelector<HTMLButtonElement>('button');

const classesOf = (element: Element | null | undefined): string[] => [...(element?.classList ?? [])];

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

    it('dresses a link that goes to another page as the button of its variant and size, no busy state on it', () => {
        view.render(
            <Button variant="primary" size="large">
                New
            </Button>,
        );
        const drawn = classesOf(button());

        expect(classesOf(Object.assign(document.createElement('a'), { className: buttonLook('primary', 'large') }))).toEqual(expect.arrayContaining(drawn));
        expect(buttonLook().split(' ')).toContain('admin-button--secondary');
        expect(buttonLook().split(' ')).not.toContain('admin-button--busy');
    });

    it('gives a link the hover and the press of its variant without the enabled state, which never matches a link', () => {
        for (const variant of ['primary', 'secondary', 'tertiary', 'link'] satisfies ButtonVariant[]) {
            view.render(<Button variant={variant}>New</Button>);
            const hovers = classesOf(button())
                .filter((name) => name.startsWith('enabled:hover:'))
                .map((name) => name.slice('enabled:'.length));
            const look = buttonLook(variant).split(' ');

            expect(hovers.length).toBeGreaterThan(0);
            expect(look).toEqual(expect.arrayContaining(hovers));
            expect(look.some((name) => /^hover:/.test(name))).toBe(true);
            expect(look.some((name) => /^motion-safe:active:scale-/.test(name))).toBe(true);
        }
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

    it('writes a destructive primary\'s label in the ink made for the red, which is pale in dark mode and carries no white', () => {
        view.render(
            <Button variant="primary" destructive>
                Delete
            </Button>,
        );

        expect(button()?.classList.contains('bg-destructive')).toBe(true);
        expect(button()?.classList.contains('text-destructive-foreground')).toBe(true);
        expect(button()?.classList.contains('text-white')).toBe(false);
        expect(button()?.classList.contains('text-primary-foreground')).toBe(false);
    });

    it('writes a link in the deepened primary, which reads at body size on a tinted ground where the raw primary does not, and a destructive link in the red', () => {
        view.render(<Button variant="link">Open</Button>);
        expect(button()?.classList.contains('text-primary-deep')).toBe(true);
        expect(button()?.classList.contains('text-primary')).toBe(false);

        view.render(
            <Button variant="link" destructive>
                Delete
            </Button>,
        );
        expect(button()?.classList.contains('text-destructive')).toBe(true);
        expect(button()?.classList.contains('text-primary-deep')).toBe(false);
    });

    it('is 44px by default, 36 small, 48 large, a pill in bold type at every height', () => {
        view.render(<Button>Save</Button>);
        expect(classesOf(button())).toEqual(expect.arrayContaining(['h-11', 'px-4.5', 'rounded-full', 'font-bold', 'text-body-sm']));
        expect(classesOf(button())).not.toEqual(expect.arrayContaining(['admin-button--small']));
        expect(classesOf(button())).not.toEqual(expect.arrayContaining(['admin-button--large']));

        view.render(<Button size="small">Save</Button>);
        expect(classesOf(button())).toEqual(expect.arrayContaining(['admin-button--small', 'h-9', 'px-3.5', 'text-sm', 'rounded-full']));
        expect(classesOf(button())).not.toContain('h-11');
        expect(classesOf(button())).not.toContain('text-body-sm');

        view.render(
            <Button variant="primary" size="large">
                Save
            </Button>,
        );
        expect(classesOf(button())).toEqual(expect.arrayContaining(['admin-button--large', 'h-12', 'rounded-full']));
        expect(classesOf(button())).not.toContain('h-11');
    });

    it('keeps a link the height of its line at any size, for use inside a sentence or a table cell', () => {
        view.render(
            <Button variant="link" size="small">
                Open
            </Button>,
        );

        expect(classesOf(button())).toEqual(expect.arrayContaining(['h-auto', 'p-0']));
        expect(classesOf(button())).not.toContain('h-9');
        expect(classesOf(button())).not.toContain('admin-button--small');
    });

    it('lets a long label wrap below the breakpoint where it is told to: held to its row, as high as its lines and never under its own height, the lines centred, the one line and the height standing above it', () => {
        const heights = [
            ['small', 'h-9', 'max-desktop:min-h-9'],
            ['default', 'h-11', 'max-desktop:min-h-11'],
            ['large', 'h-12', 'max-desktop:min-h-12'],
        ] as const;

        for (const [size, height, least] of heights) {
            view.render(
                <Button wrap size={size}>
                    Go back to the weekly pattern
                </Button>,
            );

            expect(classesOf(button())).toEqual(expect.arrayContaining(['max-desktop:h-auto', least, 'max-desktop:max-w-full', 'max-desktop:py-1.5', 'max-desktop:text-center', 'max-desktop:whitespace-normal']));
            expect(classesOf(button()).filter((name) => name.startsWith('max-desktop:min-h-'))).toEqual([least]);
            expect(classesOf(button())).toEqual(expect.arrayContaining([height, 'whitespace-nowrap']));
        }

        view.render(<Button>Save</Button>);
        expect(classesOf(button()).some((name) => name.startsWith('max-desktop:'))).toBe(false);
    });

    it('wraps nothing on a link, which keeps to its line, nor on an icon-only button, which shows no label', () => {
        view.render(
            <Button wrap variant="link">
                Open
            </Button>,
        );
        expect(classesOf(button()).some((name) => name.startsWith('max-desktop:'))).toBe(false);

        view.render(
            <Button wrap iconOnly icon={<svg />}>
                Next week
            </Button>,
        );
        expect(classesOf(button()).some((name) => name.startsWith('max-desktop:'))).toBe(false);
        expect(classesOf(button())).toContain('size-11');
    });

    it('an icon-only button keeps its name: round, square at each height, its label kept for a screen reader', () => {
        view.render(
            <Button iconOnly icon={<svg className="test-icon" />}>
                Next week
            </Button>,
        );

        expect(classesOf(button())).toEqual(expect.arrayContaining(['admin-button--icon-only', 'size-11', 'rounded-full']));
        expect(classesOf(button())).not.toContain('h-11');
        expect(classesOf(button())).not.toContain('px-4.5');
        expect(button()?.querySelector('.sr-only')?.textContent).toBe('Next week');
        expect(button()?.textContent).toBe('Next week');
        expect(button()?.querySelector('.test-icon')).not.toBeNull();

        view.render(
            <Button iconOnly size="small" icon={<svg />}>
                Next week
            </Button>,
        );
        expect(classesOf(button())).toEqual(expect.arrayContaining(['admin-button--icon-only', 'admin-button--small', 'size-9']));

        view.render(
            <Button iconOnly size="large" icon={<svg />}>
                Next week
            </Button>,
        );
        expect(classesOf(button())).toEqual(expect.arrayContaining(['admin-button--icon-only', 'admin-button--large', 'size-12']));

        view.render(<Button icon={<svg />}>Next week</Button>);
        expect(button()?.querySelector('.sr-only')).toBeNull();
        expect(classesOf(button())).not.toContain('admin-button--icon-only');
    });

    it('the icon comes before the label and is hidden from assistive technology', () => {
        view.render(<Button icon={<svg className="test-icon" />}>Add</Button>);

        const icon = button()?.querySelector('.admin-button__icon');

        expect(button()?.firstElementChild).toBe(icon);
        expect(icon?.getAttribute('aria-hidden')).toBe('true');
        expect(icon?.querySelector('.test-icon')).not.toBeNull();
        expect(button()?.textContent).toBe('Add');
    });

    it('draws a glyph that names no size of its own at 18px, and leaves one that names its size to it', () => {
        view.render(<Button icon={<svg className="test-icon" />}>Add</Button>);
        expect(classesOf(button()?.querySelector('.admin-button__icon'))).toContain('[&_svg:not([class*=\'size-\'])]:size-4.5');

        view.render(
            <Button iconOnly icon={<svg className="test-icon" />}>
                Add
            </Button>,
        );
        expect(classesOf(button()?.querySelector('.admin-button__icon'))).toContain('[&_svg:not([class*=\'size-\'])]:size-4.5');
    });

    it('while busy shows the spinner in the icon\'s place, and an icon-only button keeps the busy label for a screen reader', () => {
        view.render(
            <Button iconOnly busy busyLabel="Saving..." icon={<svg className="test-icon" />}>
                Save
            </Button>,
        );

        expect(button()?.querySelector('.test-icon')).toBeNull();
        expect(button()?.firstElementChild).toBe(button()?.querySelector('.admin-button__spinner'));
        expect(button()?.querySelector('.sr-only')?.textContent).toBe('Saving...');
    });

    it('draws the primary in the theme colour with a glow, deepened with a stronger glow on hover, the glow gone while disabled', () => {
        view.render(<Button variant="primary">Save</Button>);

        expect(classesOf(button())).toEqual(
            expect.arrayContaining(['bg-primary', 'text-primary-foreground', 'shadow-glow', 'enabled:hover:bg-primary-deep', 'enabled:hover:shadow-glow-hover', 'disabled:shadow-none']),
        );
    });

    it('sets the secondary on the card with a line inside its edge, darkened with the soft fill on hover, and the tertiary with no line', () => {
        view.render(<Button>Save</Button>);
        expect(classesOf(button())).toEqual(expect.arrayContaining(['bg-card', 'inset-ring-[1.5px]', 'inset-ring-border', 'enabled:hover:bg-muted', 'enabled:hover:inset-ring-ink-tertiary']));

        view.render(<Button variant="tertiary">Save</Button>);
        expect(classesOf(button())).toContain('enabled:hover:bg-muted');
        expect(classesOf(button()).some((name) => name.includes('inset-ring'))).toBe(false);
    });

    it('underlines a link on hover alone', () => {
        view.render(<Button variant="link">Open</Button>);

        expect(classesOf(button())).toContain('enabled:hover:underline');
        expect(classesOf(button())).not.toContain('underline');
    });

    it('the destructive secondary is the tinted red, its line the red at 40 percent, full and 2px on hover', () => {
        view.render(<Button destructive>Delete</Button>);

        expect(classesOf(button())).toEqual(
            expect.arrayContaining(['bg-destructive-soft', 'text-destructive', 'inset-ring-[1.5px]', 'inset-ring-destructive/40', 'enabled:hover:inset-ring-2', 'enabled:hover:inset-ring-destructive']),
        );
        expect(classesOf(button())).not.toContain('bg-card');
        expect(classesOf(button())).not.toContain('inset-ring-border');
    });

    it('a destructive primary carries no glow and darkens by brightness on hover', () => {
        view.render(
            <Button variant="primary" destructive>
                Delete
            </Button>,
        );

        expect(classesOf(button())).toEqual(expect.arrayContaining(['shadow-none', 'enabled:hover:bg-destructive', 'enabled:hover:brightness-92']));
        expect(classesOf(button())).not.toContain('shadow-glow');
        expect(classesOf(button())).not.toContain('enabled:hover:bg-primary-deep');
    });

    it('every variant carries the focus ring, destructive or not, in place of the faint ring it had', () => {
        for (const variant of ['primary', 'secondary', 'tertiary', 'link'] satisfies ButtonVariant[]) {
            for (const destructive of [false, true]) {
                view.render(
                    <Button variant={variant} destructive={destructive}>
                        Save
                    </Button>,
                );
                expect(classesOf(button())).toContain('focus-ring');
                expect(classesOf(button()).some((name) => name.startsWith('focus-visible:ring'))).toBe(false);
            }
        }
    });

    it('fades to 45 percent while disabled, and shrinks a little while pressed where motion is welcome', () => {
        view.render(<Button>Save</Button>);

        expect(classesOf(button())).toEqual(expect.arrayContaining(['disabled:opacity-45', 'disabled:cursor-not-allowed', 'motion-safe:enabled:active:scale-98']));
        expect(classesOf(button()).filter((name) => /(^|:)(transition|duration|ease|scale)/.test(name)).every((name) => name.startsWith('motion-safe:'))).toBe(true);
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

    it('turns its busy spinner only where the person has not asked for less motion, as the kit spinner does', () => {
        const turn = ['animate', 'spin'].join('-');

        view.render(
            <Button busy busyLabel="Saving...">
                Save
            </Button>,
        );
        const spinner = classesOf(view.host.querySelector('.admin-button__spinner'));

        expect(spinner).toContain(`motion-safe:${turn}`);
        expect(spinner.filter((name) => name.endsWith(turn))).toEqual([`motion-safe:${turn}`]);
    });
});

describe('the reach of a control drawn under 44px', () => {
    it('is a layer 44px high and never narrower than 44px, centred on the control, sized in the compact layout alone', () => {
        const parts = REACH.split(' ');

        expect(parts).toEqual(expect.arrayContaining(['relative', 'after:absolute', 'max-wide:after:h-11', 'max-wide:after:min-w-11', 'max-wide:after:w-full', 'max-wide:after:top-1/2', 'max-wide:after:left-1/2', 'max-wide:after:-translate-1/2']));
        expect(parts.filter((name) => name.startsWith('after:'))).toEqual(['after:absolute']);
        expect(parts.filter((name) => name !== 'relative' && !name.startsWith('after:')).every((name) => name.startsWith('max-wide:after:'))).toBe(true);
    });

    it('is given to the small button, icon-only or not, and to the link, and to no taller button', () => {
        const reaches = () => REACH.split(' ').every((name) => classesOf(button()).includes(name));

        view.render(<Button size="small">Edit</Button>);
        expect(reaches()).toBe(true);

        view.render(
            <Button size="small" iconOnly icon={<svg />}>
                Edit
            </Button>,
        );
        expect(reaches()).toBe(true);

        view.render(<Button variant="link">Open</Button>);
        expect(reaches()).toBe(true);
        expect(classesOf(Object.assign(document.createElement('a'), { className: buttonLook('link') }))).toEqual(expect.arrayContaining(REACH.split(' ')));

        for (const size of ['default', 'large'] as const) {
            view.render(<Button size={size}>Save</Button>);
            expect(classesOf(button()).some((name) => name.startsWith('max-wide:after:'))).toBe(false);
        }
    });

    it('gives way to a place that positions its button itself', () => {
        view.render(
            <Button size="small" className="absolute top-2 right-2">
                Close
            </Button>,
        );

        expect(classesOf(button())).toContain('absolute');
        expect(classesOf(button())).not.toContain('relative');
    });
});

describe('the button in forced colours', () => {
    it('draws a real line round the primary and the secondary, whose fill and ring the system drops, and none round the tertiary or the link', () => {
        for (const destructive of [false, true]) {
            for (const variant of ['primary', 'secondary'] satisfies ButtonVariant[]) {
                view.render(
                    <Button variant={variant} destructive={destructive}>
                        Save
                    </Button>,
                );
                expect(classesOf(button())).toContain(FORCED_EDGE);
            }

            for (const variant of ['tertiary', 'link'] satisfies ButtonVariant[]) {
                view.render(
                    <Button variant={variant} destructive={destructive}>
                        Save
                    </Button>,
                );
                expect(classesOf(button()).some((name) => name.startsWith('forced-colors:'))).toBe(false);
            }
        }

        expect(buttonLook('secondary').split(' ')).toContain(FORCED_EDGE);
    });
});
