import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { keyedShell } from '@/testing/keyed';
import type { Theme } from '../theme';
import { Controls } from './Controls';

/*
 * The shell's controls: the language control where one is handed, the light and dark switch as a
 * round icon button named by the theme it switches to, then the signed-in user's name, cut short
 * with the whole name in its tooltip, and Sign out with its icon and its busy state. Where nobody
 * is signed in, neither the name nor Sign out renders. The words are the shell's, read here
 * through the keyed table.
 */

const shell = keyedShell();

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => {
    mounted.unmount();
});

type Options = { theme?: Theme; onToggleTheme?: () => void; signingOut?: boolean; onSignOut?: (() => void) | undefined; userName?: string };

function render(options: Options = {}, language?: ReactNode): void {
    const { theme = 'light', onToggleTheme = () => {}, signingOut = false, userName = 'Alex Owner' } = options;
    // Passed as undefined, nobody is signed in; left out, somebody is.
    const onSignOut = 'onSignOut' in options ? options.onSignOut : () => {};

    mounted.render(<Controls userName={userName} theme={theme} onToggleTheme={onToggleTheme} signingOut={signingOut} onSignOut={onSignOut} language={language} />);
}

const themeSwitch = () => mounted.host.querySelector<HTMLButtonElement>('.admin-theme');

describe('Controls', () => {
    it('draws the theme switch as a round icon button, a moon in light named for the dark theme and a sun in dark named for the light', () => {
        render({ theme: 'light' });

        expect(themeSwitch()?.classList.contains('admin-button--icon-only')).toBe(true);
        expect(themeSwitch()?.textContent).toBe(shell.text('top_bar.dark'));
        expect(themeSwitch()?.querySelector('.sr-only')?.textContent).toBe(shell.text('top_bar.dark'));
        expect(themeSwitch()?.querySelector('svg')?.classList.contains('lucide-moon')).toBe(true);

        render({ theme: 'dark' });

        expect(themeSwitch()?.textContent).toBe(shell.text('top_bar.light'));
        expect(themeSwitch()?.querySelector('svg')?.classList.contains('lucide-sun')).toBe(true);
    });

    it('switches the theme on a press', () => {
        const toggle = vi.fn();
        render({ onToggleTheme: toggle });

        press(themeSwitch());

        expect(toggle).toHaveBeenCalledOnce();
    });

    it('cuts a long name short with an ellipsis and shows it whole in its tooltip', () => {
        const name = 'Alexandra Konstantinopoulou-Papadimitriou';
        render({ userName: name });

        const user = mounted.host.querySelector('.admin-user');

        expect(user?.textContent).toBe(name);
        expect(user?.getAttribute('title')).toBe(name);
        expect(user?.classList.contains('truncate')).toBe(true);
        expect([...(user?.classList ?? [])].some((token) => token.startsWith('max-w-'))).toBe(true);
    });

    it('signs out with the sign-out icon, and shows the control busy while it is on its way', () => {
        const signOut = vi.fn();
        render({ onSignOut: signOut });

        const button = () => mounted.host.querySelector<HTMLButtonElement>('.admin-signout');

        expect(button()?.textContent).toBe(shell.text('top_bar.sign_out'));
        expect(button()?.querySelector('.admin-button__icon svg')?.classList.contains('lucide-log-out')).toBe(true);

        press(button());
        expect(signOut).toHaveBeenCalledOnce();

        render({ onSignOut: signOut, signingOut: true });

        expect(button()?.disabled).toBe(true);
        expect(button()?.getAttribute('aria-busy')).toBe('true');
        expect(button()?.textContent).toBe(shell.text('top_bar.signing_out'));
    });

    it('renders neither the name nor Sign out where nobody is signed in, the theme switch still there', () => {
        render({ onSignOut: undefined });

        expect(mounted.host.querySelector('.admin-user')).toBeNull();
        expect(mounted.host.querySelector('.admin-signout')).toBeNull();
        expect(themeSwitch()).not.toBeNull();
    });

    it('puts the language control first, before the theme switch, the name and Sign out', () => {
        render({}, <div className="admin-language" />);

        const hooks = ['admin-language', 'admin-theme', 'admin-user', 'admin-signout'];
        const order = [...(mounted.host.querySelector('.admin-controls')?.children ?? [])].map((child) => hooks.find((hook) => child.classList.contains(hook)));

        expect(order).toEqual(hooks);
    });

    it('wraps its controls onto further lines below the desktop width, where one row of them is wider than a phone', () => {
        render({}, <div className="admin-language" />);

        const group = mounted.host.querySelector('.admin-controls');

        expect(group?.classList.contains('flex')).toBe(true);
        expect(group?.classList.contains('max-desktop:flex-wrap')).toBe(true);
    });

    it('stands its controls in a column where it is stacked, the theme switch with its word beside its icon and the name across the width, in the same order', () => {
        mounted.render(<Controls stacked userName="Alex Owner" theme="light" onToggleTheme={() => {}} signingOut={false} onSignOut={() => {}} language={<div className="admin-language" />} />);

        const group = mounted.host.querySelector('.admin-controls');
        const hooks = ['admin-language', 'admin-theme', 'admin-user', 'admin-signout'];

        expect(group?.classList.contains('flex-col')).toBe(true);
        expect(group?.classList.contains('max-desktop:flex-wrap')).toBe(false);
        expect([...(group?.children ?? [])].map((child) => hooks.find((hook) => child.classList.contains(hook)))).toEqual(hooks);
        expect(themeSwitch()?.classList.contains('admin-button--icon-only')).toBe(false);
        expect(themeSwitch()?.textContent).toBe(shell.text('top_bar.dark'));
        expect(themeSwitch()?.querySelector('svg')?.classList.contains('lucide-moon')).toBe(true);
        expect(mounted.host.querySelector('.admin-user')?.classList.contains('max-w-40')).toBe(false);
        expect(mounted.host.querySelector('.admin-user')?.classList.contains('truncate')).toBe(true);
        expect(mounted.host.querySelector('.admin-user')?.getAttribute('title')).toBe('Alex Owner');
    });
});
