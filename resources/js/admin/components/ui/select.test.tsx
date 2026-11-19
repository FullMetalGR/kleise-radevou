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
    it('stands 46px high in the field\'s ring, with a chevron at the right kept from a screen reader', () => {
        view.render(<Select options={[{ value: 'a', label: 'A' }]} value="a" onChange={() => {}} />);

        const select = view.host.querySelector('select');
        const chevron = select?.parentElement?.querySelector('svg');

        for (const look of ['h-11.5', 'appearance-none', 'pr-10', 'rounded-field', 'bg-card', 'inset-ring-[1.5px]', 'inset-ring-border', 'focus-ring-field']) {
            expect(select?.classList.contains(look)).toBe(true);
        }
        expect(select?.classList.contains('enabled:hover:not-aria-invalid:inset-ring-ink-tertiary')).toBe(true);
        expect(chevron?.getAttribute('aria-hidden')).toBe('true');
        expect(chevron?.classList.contains('size-4')).toBe(true);
        expect(select?.contains(chevron ?? null)).toBe(false);
    });

    it('sits in a frame its longest option cannot widen, and shrinks with it', () => {
        view.render(<Select options={[{ value: 'a', label: 'A name far longer than the column the select stands in' }]} value="a" onChange={() => {}} />);

        const select = view.host.querySelector('select');
        const frame = select?.parentElement;

        expect(frame?.classList.contains('admin-select__frame')).toBe(true);
        expect(frame?.classList.contains('grid')).toBe(true);
        expect(frame?.classList.contains('grid-cols-1')).toBe(true);
        expect(select?.classList.contains('min-w-0')).toBe(true);
        expect(select?.classList.contains('w-full')).toBe(true);
    });

    it('lets a dense row pass its own height', () => {
        view.render(<Select options={[{ value: 'a', label: 'A' }]} value="a" onChange={() => {}} className="h-9" />);

        expect(view.host.querySelector('select')?.classList.contains('h-9')).toBe(true);
        expect(view.host.querySelector('select')?.classList.contains('h-11.5')).toBe(false);
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
