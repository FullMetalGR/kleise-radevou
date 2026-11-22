import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { Door } from './Door';

/*
 * The frame both sign-in pages of a business share: a narrow column centred on the page colour,
 * the mark above one form card that holds the page's heading and its form. Without a mark the
 * card stands alone at the head of the column.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = (selector: string) => view.host.querySelector(selector);

describe('the door', () => {
    it('is the page\'s main landmark under the caller\'s class, at least as high as the window', () => {
        view.render(
            <Door className="probe-door">
                <p className="probe-child">Inside</p>
            </Door>,
        );

        const main = q('main');

        expect(main?.classList.contains('probe-door')).toBe(true);
        expect(main?.classList.contains('min-h-screen')).toBe(true);
        expect(view.host.querySelectorAll('main')).toHaveLength(1);
    });

    it('holds a column centred on the page, at most 420 pixels wide and the full width below that', () => {
        view.render(
            <Door className="probe-door">
                <p>Inside</p>
            </Door>,
        );

        const column = q('main > .admin-door');

        expect([...(column?.classList ?? [])]).toEqual(expect.arrayContaining(['mx-auto', 'w-full', 'max-w-[26.25rem]']));
    });

    it('puts the mark first, then one form card holding the children', () => {
        view.render(
            <Door className="probe-door" mark={<span className="probe-mark">Mark</span>}>
                <h1>Heading</h1>
                <p className="probe-child">Inside</p>
            </Door>,
        );

        const column = q('.admin-door');
        const cards = view.host.querySelectorAll('.admin-door__card');
        const card = cards[0];

        expect(column?.children).toHaveLength(2);
        expect(column?.firstElementChild?.matches('.admin-door__mark')).toBe(true);
        expect(column?.firstElementChild?.querySelector('.probe-mark')?.textContent).toBe('Mark');
        expect(column?.lastElementChild).toBe(card);
        expect(cards).toHaveLength(1);
        expect(card?.tagName).toBe('SECTION');
        expect([...(card?.classList ?? [])]).toEqual(expect.arrayContaining(['bg-card', 'rounded-tile', 'shadow-card', 'px-6.5', 'pt-6', 'pb-6.5']));
        expect(card?.querySelector('h1')?.textContent).toBe('Heading');
        expect(card?.querySelector('.probe-child')?.textContent).toBe('Inside');
    });

    it('leaves nothing in the mark\'s place where it is given none', () => {
        view.render(
            <Door className="probe-door">
                <p className="probe-child">Inside</p>
            </Door>,
        );

        const column = q('.admin-door');

        expect(q('.admin-door__mark')).toBeNull();
        expect(column?.children).toHaveLength(1);
        expect(column?.firstElementChild?.matches('.admin-door__card')).toBe(true);
    });
});
