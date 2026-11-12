import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DropdownMenu, type MenuItem } from './dropdown-menu';
import { key, mount, press, settle, type Mounted } from './harness';

/*
 * The dropdown menu: it opens from its trigger by a pointer or the keyboard, the arrows move
 * between the items, a handler item runs its handler and closes, an href item is a link, and
 * Escape closes and hands focus back to the trigger. The library opens on a primary pointer
 * press, so a press here is the pointerdown it listens for.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

// The library hands focus back a task after a menu unmounts; that task runs here, not inside the
// next case.
afterEach(async () => {
    view.unmount();
    await settle();
});

function pointer(element: Element | null): void {
    if (element === null) {
        throw new Error('Nothing to press.');
    }

    act(() => {
        element.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, button: 0, ctrlKey: false, pointerType: 'mouse' }));
    });
}

const trigger = () => document.querySelector<HTMLElement>('.trigger');
const menu = () => document.querySelector<HTMLElement>('.admin-menu');
const items = () => [...document.querySelectorAll<HTMLElement>('.admin-menu__item')];

function render(onBook = vi.fn(), onClose = vi.fn()): void {
    const list: MenuItem[] = [
        { key: 'book', label: 'New booking', className: 'book', onSelect: onBook },
        { key: 'close', label: 'Closure', description: 'Close the business or a member for a day', className: 'close', onSelect: onClose },
        { key: 'staff', label: 'Open staff page', className: 'staff', href: '/admin/staff/2' },
    ];
    view.render(
        <DropdownMenu
            label="Add"
            items={list}
            trigger={
                <button type="button" className="trigger">
                    Add
                </button>
            }
        />,
    );
}

describe('the dropdown menu', () => {
    it('opens from a press on its trigger, a named menu of its items with their descriptions', () => {
        render();
        expect(menu()).toBeNull();
        expect(trigger()?.getAttribute('aria-haspopup')).toBe('menu');

        pointer(trigger());

        expect(menu()?.getAttribute('role')).toBe('menu');
        // The label names the menu: no aria-labelledby beside it, which would win and name the menu by its trigger.
        expect(menu()?.getAttribute('aria-label')).toBe('Add');
        expect(menu()?.hasAttribute('aria-labelledby')).toBe(false);
        expect(items().map((item) => item.querySelector('.admin-menu__label')?.textContent)).toEqual(['New booking', 'Closure', 'Open staff page']);
        expect(items().map((item) => item.getAttribute('role'))).toEqual(['menuitem', 'menuitem', 'menuitem']);
        expect(document.querySelector('.close .admin-menu__description')?.textContent).toBe('Close the business or a member for a day');
        expect(document.querySelector('.book .admin-menu__description')).toBeNull();
        expect(trigger()?.getAttribute('aria-expanded')).toBe('true');
    });

    it('closes the list with its note behind a rule where one is given, which is no item and takes no focus', async () => {
        view.render(
            <DropdownMenu
                label="Add"
                note="What a closure is"
                items={[
                    { key: 'book', label: 'New booking', className: 'book', onSelect: vi.fn() },
                    { key: 'close', label: 'Closure', className: 'close', onSelect: vi.fn() },
                ]}
                trigger={
                    <button type="button" className="trigger">
                        Add
                    </button>
                }
            />,
        );
        trigger()?.focus();
        key(trigger(), 'Enter');
        await settle();

        expect(menu()?.lastElementChild?.classList.contains('admin-menu__note')).toBe(true);
        expect(menu()?.lastElementChild?.textContent).toBe('What a closure is');
        expect(menu()?.lastElementChild?.previousElementSibling?.getAttribute('role')).toBe('separator');
        expect(items()).toHaveLength(2);
        key(document.activeElement, 'ArrowDown');
        await settle();
        key(document.activeElement, 'ArrowDown');
        await settle();
        expect(document.activeElement).toBe(items()[1]);
    });

    it('heads the list with its title where one is given, which is no item and takes no focus', async () => {
        view.render(
            <DropdownMenu
                label="Elena Papadaki, 05/11/2026"
                title="Elena Papadaki, 05/11/2026"
                items={[{ key: 'book', label: 'New booking here', className: 'book', onSelect: vi.fn() }]}
                trigger={
                    <button type="button" className="trigger">
                        Menu
                    </button>
                }
            />,
        );
        trigger()?.focus();
        key(trigger(), 'Enter');
        await settle();

        expect(menu()?.querySelector('.admin-menu__title')?.textContent).toBe('Elena Papadaki, 05/11/2026');
        expect(menu()?.firstElementChild?.classList.contains('admin-menu__title')).toBe(true);
        expect(items().map((item) => item.textContent)).toEqual(['New booking here']);
        expect(document.activeElement).toBe(items()[0]);
    });

    it('opens from the keyboard on the first item, and the arrows move between the items', async () => {
        render();
        trigger()?.focus();

        key(trigger(), 'Enter');
        await settle();
        expect(document.activeElement).toBe(items()[0]);

        key(document.activeElement, 'ArrowDown');
        await settle();
        expect(document.activeElement).toBe(items()[1]);

        key(document.activeElement, 'ArrowDown');
        await settle();
        expect(document.activeElement).toBe(items()[2]);

        key(document.activeElement, 'ArrowUp');
        await settle();
        expect(document.activeElement).toBe(items()[1]);
    });

    it('runs a handler item and closes', async () => {
        const onClose = vi.fn();
        render(vi.fn(), onClose);
        trigger()?.focus();
        key(trigger(), 'Enter');
        await settle();

        press(document.querySelector('.close'));

        expect(onClose).toHaveBeenCalledOnce();
        expect(menu()).toBeNull();
    });

    it('selects the focused item with Enter', async () => {
        const onBook = vi.fn();
        render(onBook);
        trigger()?.focus();
        key(trigger(), 'Enter');
        await settle();

        key(document.activeElement, 'Enter');

        expect(onBook).toHaveBeenCalledOnce();
    });

    it('renders an href item as a link to it', async () => {
        render();
        trigger()?.focus();
        key(trigger(), 'Enter');
        await settle();

        const link = document.querySelector('.staff');
        expect(link?.tagName).toBe('A');
        expect(link?.getAttribute('href')).toBe('/admin/staff/2');
    });

    it('closes on Escape and hands focus back to the trigger', async () => {
        render();
        trigger()?.focus();
        key(trigger(), 'Enter');
        await settle();

        key(document.activeElement, 'Escape');
        await settle();

        expect(menu()).toBeNull();
        expect(document.activeElement).toBe(trigger());
    });
});
