import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { keyedShell } from '@/testing/keyed';
import { MenuButton } from './MenuButton';

/*
 * The round Menu button: three bars that turn into a cross while the drawer is open, named Menu
 * while it is shut and Close while it is open, saying it opens a dialog and whether that dialog is
 * up. It carries a count only where it is handed one.
 */

const shell = keyedShell();

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => {
    mounted.unmount();
});

const button = () => mounted.host.querySelector<HTMLButtonElement>('button.admin-menu-button');

describe('MenuButton', () => {
    it('is a round button named Menu that says it opens a dialog, shut', () => {
        const onClick = vi.fn();

        mounted.render(<MenuButton open={false} pending={null} onClick={onClick} />);

        expect(button()?.textContent).toBe(shell.text('nav.menu'));
        expect(button()?.getAttribute('aria-haspopup')).toBe('dialog');
        expect(button()?.getAttribute('aria-expanded')).toBe('false');
        expect(button()?.classList).toContain('size-12');
        expect(button()?.classList).toContain('rounded-full');
        expect(button()?.querySelectorAll('.admin-menu-button__bar')).toHaveLength(3);
        // The dropdown menu's list owns the admin-menu class and its parts; the button shares neither.
        expect(button()?.classList.contains('admin-menu')).toBe(false);
        expect(button()?.querySelectorAll('[class*="admin-menu__"]')).toHaveLength(0);
        expect(button()?.querySelector('[role="img"]')).toBeNull();

        press(button());

        expect(onClick).toHaveBeenCalledTimes(1);
    });

    it('is named Close and says the dialog is up while the drawer is open, its bars a cross', () => {
        mounted.render(<MenuButton open pending={null} onClick={() => {}} />);

        expect(button()?.textContent).toBe(shell.text('nav.close'));
        expect(button()?.getAttribute('aria-expanded')).toBe('true');
        expect(button()?.getAttribute('data-open')).toBe('true');
    });

    it('carries the count it is handed, named for what it counts', () => {
        mounted.render(<MenuButton open={false} pending={4} onClick={() => {}} />);

        const badge = button()?.querySelector('.admin-menu-pending');

        expect(badge?.getAttribute('role')).toBe('img');
        expect(badge?.getAttribute('aria-label')).toBe(shell.choice('nav.pending', 4));
        expect(badge?.textContent).toBe('4');
    });
});
