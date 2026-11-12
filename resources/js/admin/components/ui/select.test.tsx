import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { Select } from './select';

/*
 * The select: flat options first, then optional groups, and a group with no options renders
 * nothing at all, neither the optgroup nor its label.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

describe('the select', () => {
    it('stands 32px high, the text input\'s height', () => {
        view.render(<Select options={[{ value: 'a', label: 'A' }]} value="a" onChange={() => {}} />);

        expect(view.host.querySelector('select')?.classList.contains('h-8')).toBe(true);
    });

    it('renders the flat options before the groups', () => {
        view.render(
            <Select
                options={[{ value: '', label: 'Anyone' }]}
                groups={[{ label: 'Active', options: [{ value: '4', label: 'Maria' }] }]}
                value=""
                onChange={() => {}}
            />,
        );

        const children = [...(view.host.querySelector('select')?.children ?? [])].map((child) => `${child.tagName}:${child.getAttribute('label') ?? child.textContent}`);

        expect(children).toEqual(['OPTION:Anyone', 'OPTGROUP:Active']);
    });

    it('renders a group with no options as nothing, no optgroup and no label', () => {
        view.render(
            <Select
                options={[{ value: '', label: 'Anyone' }]}
                groups={[
                    { label: 'Active', options: [{ value: '4', label: 'Maria' }] },
                    { label: 'Inactive', options: [] },
                ]}
                value=""
                onChange={() => {}}
            />,
        );

        expect(view.host.querySelectorAll('optgroup')).toHaveLength(1);
        expect(view.host.innerHTML).not.toContain('Inactive');
    });

    it('answers the chosen value through its handler', () => {
        const chosen: string[] = [];

        view.render(<Select options={[{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }]} value="a" onChange={(value) => chosen.push(value)} />);

        const select = view.host.querySelector('select');

        if (select !== null) {
            select.value = 'b';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        expect(chosen).toEqual(['b']);
    });
});
