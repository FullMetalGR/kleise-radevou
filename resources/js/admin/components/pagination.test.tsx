import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { keyedShell } from '@/testing/keyed';
import { Pagination } from './pagination';
import { mount, onPhone, press, type Mounted } from './ui/harness';

/*
 * The pagination of a paged table: Previous and Next as small buttons, each refused at its end,
 * and the page line between them, the current page filled in ink, the footer ruled off the table
 * above it. On a phone Previous and Next are round arrows at the full height, named for a screen
 * reader, so the page line keeps to one line between them.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = <T extends HTMLElement = HTMLElement>(selector: string) => view.host.querySelector<T>(selector);

describe('the pagination', () => {
    it('reads the page of the last, between Previous and Next at the small size', () => {
        view.render(<Pagination page={2} total={45} pageSize={20} onPage={() => {}} />);

        expect(q('.admin-pagination__page')?.textContent).toBe(keyedShell().text('kit.pagination.page', { page: '2', last: '3' }));
        expect(q('.admin-pagination__previous')?.textContent).toBe(keyedShell().text('kit.pagination.previous'));
        expect(q('.admin-pagination__next')?.textContent).toBe(keyedShell().text('kit.pagination.next'));

        for (const button of [q('.admin-pagination__previous'), q('.admin-pagination__next')]) {
            expect(button?.classList.contains('admin-button--small')).toBe(true);
            expect(button?.classList.contains('h-9')).toBe(true);
        }
    });

    it('marks the current page in the ink fill, under a rule with the footer\'s room around it', () => {
        view.render(<Pagination page={1} total={45} pageSize={20} onPage={() => {}} />);

        const page = q('.admin-pagination__page');
        const footer = q('.admin-pagination');

        expect(page?.classList.contains('bg-foreground')).toBe(true);
        expect(page?.classList.contains('text-background')).toBe(true);
        // A field's rounding: a pill on one line, and still a rounded box where a narrow screen wraps the line, which a full rounding would turn into a blob.
        expect(page?.classList.contains('rounded-field')).toBe(true);
        expect(page?.classList.contains('rounded-full')).toBe(false);
        expect(footer?.classList.contains('border-t')).toBe(true);
        expect(footer?.classList.contains('px-6')).toBe(true);
        expect(footer?.classList.contains('py-3.5')).toBe(true);
    });

    it('refuses Previous on the first page and Next on the last, and moves one page on a press', () => {
        const onPage = vi.fn();

        view.render(<Pagination page={1} total={45} pageSize={20} onPage={onPage} />);
        expect(q<HTMLButtonElement>('.admin-pagination__previous')?.disabled).toBe(true);
        expect(q<HTMLButtonElement>('.admin-pagination__next')?.disabled).toBe(false);
        press(q('.admin-pagination__next'));
        expect(onPage).toHaveBeenLastCalledWith(2);

        view.render(<Pagination page={3} total={45} pageSize={20} onPage={onPage} />);
        expect(q<HTMLButtonElement>('.admin-pagination__next')?.disabled).toBe(true);
        press(q('.admin-pagination__previous'));
        expect(onPage).toHaveBeenLastCalledWith(2);
    });

    it('counts one page where there are no rows at all', () => {
        view.render(<Pagination page={1} total={0} pageSize={20} onPage={() => {}} />);

        expect(q('.admin-pagination__page')?.textContent).toBe(keyedShell().text('kit.pagination.page', { page: '1', last: '1' }));
        expect(q<HTMLButtonElement>('.admin-pagination__next')?.disabled).toBe(true);
    });

    it('draws Previous and Next as round arrows at the full height on a phone, each named in its word for a screen reader and refused at its end as before', () => {
        const undo = onPhone();
        const onPage = vi.fn();

        try {
            view.render(<Pagination page={1} total={45} pageSize={20} onPage={onPage} />);

            for (const [button, word] of [
                [q('.admin-pagination__previous'), 'kit.pagination.previous'],
                [q('.admin-pagination__next'), 'kit.pagination.next'],
            ] as const) {
                expect(button?.classList.contains('admin-button--icon-only')).toBe(true);
                expect(button?.classList.contains('size-11')).toBe(true);
                expect(button?.classList.contains('admin-button--small')).toBe(false);
                expect(button?.querySelector('.admin-button__icon[aria-hidden="true"] svg')).not.toBeNull();
                expect(button?.querySelector('.sr-only')?.textContent).toBe(keyedShell().text(word));
            }

            expect(q('.admin-pagination__page')?.textContent).toBe(keyedShell().text('kit.pagination.page', { page: '1', last: '3' }));
            expect(q<HTMLButtonElement>('.admin-pagination__previous')?.disabled).toBe(true);
            press(q('.admin-pagination__next'));
            expect(onPage).toHaveBeenLastCalledWith(2);
        } finally {
            undo();
        }
    });

    it('keeps Previous and Next in words with no arrow from the breakpoint up', () => {
        view.render(<Pagination page={2} total={45} pageSize={20} onPage={() => {}} />);

        for (const button of [q('.admin-pagination__previous'), q('.admin-pagination__next')]) {
            expect(button?.querySelector('svg')).toBeNull();
            expect(button?.querySelector('.sr-only')).toBeNull();
        }
    });
});
