import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, press, type Mounted } from './harness';
import { Segmented } from './segmented';

/*
 * The segmented control: one joined control with a group role and an accessible name, the
 * chosen option carried in aria-pressed and not in the fill alone. A click chooses; arrowing is
 * not required.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const OPTIONS = [
    { value: 'day', label: 'day-token' },
    { value: 'week', label: 'week-token' },
    { value: 'month', label: 'month-token' },
] as const;

function Harness() {
    const [value, setValue] = useState<string>('week');

    return <Segmented label="Calendar view" options={OPTIONS} value={value} onChange={setValue} />;
}

const pressed = () => [...view.host.querySelectorAll('.admin-segmented__option')].map((option) => `${option.textContent}:${option.getAttribute('aria-pressed')}`);

describe('the segmented control', () => {
    it('is one group with an accessible name', () => {
        view.render(<Harness />);

        const group = view.host.querySelector('.admin-segmented');

        expect(group?.getAttribute('role')).toBe('group');
        expect(group?.getAttribute('aria-label')).toBe('Calendar view');
        expect(view.host.querySelectorAll('[role="group"]')).toHaveLength(1);
    });

    it('carries the choice in aria-pressed, true on the chosen option and false on the others', () => {
        view.render(<Harness />);

        expect(pressed()).toEqual(['day-token:false', 'week-token:true', 'month-token:false']);
    });

    it('chooses on a click', () => {
        view.render(<Harness />);

        press(view.host.querySelectorAll('.admin-segmented__option')[2] ?? null);

        expect(pressed()).toEqual(['day-token:false', 'week-token:false', 'month-token:true']);
    });

    it('fills the chosen segment dark, the pressed one alone, and says so to a screen reader', () => {
        view.render(<Harness />);

        const options = [...view.host.querySelectorAll('.admin-segmented__option')];
        const filled = (option: Element) => option.classList.contains('bg-foreground') && option.classList.contains('text-background');

        expect(options.map((option) => [option.getAttribute('aria-pressed'), filled(option)])).toEqual([
            ['false', false],
            ['true', true],
            ['false', false],
        ]);
    });

    it('is a rounded group on the card with a line ring, each segment a 38px pill in 600 at 14.5px with the focus ring', () => {
        view.render(<Harness />);

        const group = view.host.querySelector('.admin-segmented');

        expect(['rounded-inner', 'bg-card', 'p-1', 'inset-ring', 'inset-ring-border'].filter((name) => !group?.classList.contains(name))).toEqual([]);
        expect([...(group?.classList ?? [])].filter((name) => name.startsWith('rounded-'))).toEqual(['rounded-inner']);

        for (const option of view.host.querySelectorAll('.admin-segmented__option')) {
            expect(['h-9.5', 'rounded-full', 'px-4.5', 'text-body-sm', 'font-semibold', 'focus-ring'].filter((name) => !option.classList.contains(name))).toEqual([]);
        }
    });

    it('wraps its segments onto a second row where one row does not fit, each segment\'s words on one line, and never scrolls inside itself', () => {
        view.render(<Harness />);

        const group = view.host.querySelector('.admin-segmented');

        expect(group?.classList.contains('flex-wrap')).toBe(true);
        expect([...(group?.classList ?? [])].filter((name) => name.startsWith('overflow-'))).toEqual([]);

        for (const option of view.host.querySelectorAll('.admin-segmented__option')) {
            expect(option.classList.contains('whitespace-nowrap')).toBe(true);
        }
    });

    it('holds its buttons out of any form submission', () => {
        view.render(<Harness />);

        for (const option of view.host.querySelectorAll('.admin-segmented__option')) {
            expect(option.getAttribute('type')).toBe('button');
        }
    });
});
