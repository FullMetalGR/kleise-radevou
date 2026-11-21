import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted } from './harness';
import { SearchField } from './search-field';

/*
 * The search field: the field shell with its label shown, around a text input of the search type
 * drawn as a pill with a search icon at its start. The icon is decoration; the label names the
 * input. Whatever the place hands the input reaches it, its class beside the pill's own. There is
 * no clear button.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const input = () => view.host.querySelector<HTMLInputElement>('input');

describe('the search field', () => {
    it('is the field shell around a search input, the label shown and tied to it', () => {
        view.render(<SearchField id="people-search" label="Search" />);

        const label = view.host.querySelector('label');

        expect(view.host.querySelector('.admin-field.admin-search-field')).not.toBeNull();
        expect(label?.getAttribute('for')).toBe('people-search');
        expect(label?.textContent).toBe('Search');
        expect(label?.classList.contains('sr-only')).toBe(false);
        expect(input()?.id).toBe('people-search');
        expect(input()?.getAttribute('type')).toBe('search');
        expect(input()?.classList.contains('admin-input')).toBe(true);
    });

    it('draws the input as a pill at the field\'s height with a search icon at its start, hidden from assistive technology', () => {
        view.render(<SearchField id="people-search" label="Search" />);

        const icon = view.host.querySelector('svg.lucide-search');

        expect(input()?.classList.contains('rounded-full')).toBe(true);
        expect([...(input()?.classList ?? [])].filter((look) => look.startsWith('rounded-'))).toEqual(['rounded-full']);
        expect(input()?.classList.contains('h-11.5')).toBe(true);
        expect(input()?.classList.contains('ps-11')).toBe(true);
        expect(icon?.getAttribute('aria-hidden')).toBe('true');
        expect(icon?.parentElement?.contains(input() ?? null)).toBe(true);
    });

    it('passes the input\'s own class and every other attribute through to it', () => {
        const typed = vi.fn();
        view.render(<SearchField id="people-search" label="Search" className="place-search" placeholder="Name or email" value="ele" onChange={typed} autoComplete="off" />);

        expect(input()?.classList.contains('place-search')).toBe(true);
        expect(input()?.classList.contains('rounded-full')).toBe(true);
        expect(input()?.getAttribute('placeholder')).toBe('Name or email');
        expect(input()?.value).toBe('ele');
        expect(input()?.getAttribute('autocomplete')).toBe('off');
    });

    it('has no clear button', () => {
        view.render(<SearchField id="people-search" label="Search" value="ele" onChange={() => {}} />);

        expect(view.host.querySelectorAll('button')).toHaveLength(0);
        expect(view.host.querySelectorAll('input')).toHaveLength(1);
    });
});
