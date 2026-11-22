import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from './harness';
import { Breadcrumb, CRUMB_LINK } from './breadcrumb';
import { REACH } from './button';

/*
 * The trail back from a detail screen to its list: a navigation region named by its label, each
 * step before the last a link, the last the page itself, marked as current and never a link.
 * A link that the screen answers itself stops the browser's own navigation and hands the press
 * to the screen, once. The slashes between steps are drawn for the eye and hidden from a reader.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = (selector: string) => view.host.querySelector(selector);

describe('the breadcrumb', () => {
    it('is a navigation region named by its label', () => {
        view.render(<Breadcrumb label="trail-token" items={[{ label: 'list-token', href: '/list' }, { label: 'page-token' }]} />);

        const nav = q('nav.admin-breadcrumb');

        expect(nav?.getAttribute('aria-label')).toBe('trail-token');
    });

    it('links each step that has an address, under the list hook', () => {
        view.render(<Breadcrumb label="trail-token" items={[{ label: 'list-token', href: '/list' }, { label: 'page-token' }]} />);

        const links = view.host.querySelectorAll('nav.admin-breadcrumb a');

        expect(links).toHaveLength(1);
        expect(links[0]?.getAttribute('href')).toBe('/list');
        expect(links[0]?.textContent).toBe('list-token');
        expect(links[0]?.classList.contains('admin-breadcrumb__list')).toBe(true);
    });

    it('stops the browser\'s navigation on a link the screen answers, and hands the press over once', () => {
        const back = vi.fn();

        view.render(<Breadcrumb label="trail-token" items={[{ label: 'list-token', href: '/list', onClick: back }, { label: 'page-token' }]} />);

        const link = q('.admin-breadcrumb__list');
        let prevented = false;

        view.host.addEventListener('click', (seen) => {
            prevented = seen.defaultPrevented;
        });
        press(link);

        expect(back).toHaveBeenCalledTimes(1);
        expect(prevented).toBe(true);
    });

    it('marks the last step as the current page and never links it, even where it is given an address', () => {
        view.render(<Breadcrumb label="trail-token" items={[{ label: 'list-token', href: '/list' }, { label: 'page-token', href: '/page' }]} />);

        const current = q('.admin-breadcrumb__current');

        expect(current?.textContent).toBe('page-token');
        expect(current?.getAttribute('aria-current')).toBe('page');
        expect(current?.tagName).not.toBe('A');
        expect(current?.closest('a')).toBeNull();
        expect(view.host.querySelectorAll('[aria-current]')).toHaveLength(1);
    });

    it('hides each slash between the steps from a reader', () => {
        view.render(<Breadcrumb label="trail-token" items={[{ label: 'home-token', href: '/' }, { label: 'list-token', href: '/list' }, { label: 'page-token' }]} />);

        const slashes = [...view.host.querySelectorAll('nav.admin-breadcrumb [aria-hidden="true"]')];

        expect(slashes).toHaveLength(2);
        expect(slashes.every((slash) => slash.textContent?.trim() === '/')).toBe(true);
        expect([...view.host.querySelectorAll('nav.admin-breadcrumb li')].map((step) => step.textContent?.trim())).toEqual(['home-token', '/', 'list-token', '/', 'page-token']);
    });

    it('sets the links in the deep primary ink and the current page in the ink, both bold', () => {
        view.render(<Breadcrumb label="trail-token" items={[{ label: 'list-token', href: '/list' }, { label: 'page-token' }]} />);

        expect([...(q('.admin-breadcrumb__list')?.classList ?? [])]).toEqual(expect.arrayContaining(['text-primary-deep', 'font-semibold']));
        expect([...(q('.admin-breadcrumb__current')?.classList ?? [])]).toEqual(expect.arrayContaining(['text-foreground', 'font-semibold']));
        expect([...(q('nav.admin-breadcrumb')?.classList ?? [])]).toContain('text-body-sm');
    });

    it('underlines a link at rest in every theme, so weight is not all that tells it from the words beside it, and thickens the line on hover', () => {
        view.render(<Breadcrumb label="trail-token" items={[{ label: 'list-token', href: '/list' }, { label: 'page-token' }]} />);

        const link = [...(q('.admin-breadcrumb__list')?.classList ?? [])];

        expect(link).toEqual(expect.arrayContaining(['underline', 'underline-offset-4', 'hover:decoration-2']));
        expect(link.filter((name) => name.startsWith('hover:'))).toEqual(['hover:decoration-2']);
        expect(CRUMB_LINK.split(' ')).toEqual(expect.arrayContaining(['underline', 'hover:decoration-2']));
    });

    it('gives a link the kit\'s 44px reach in the compact layout, the line of text being far lower than a finger', () => {
        view.render(<Breadcrumb label="trail-token" items={[{ label: 'list-token', href: '/list' }, { label: 'page-token' }]} />);

        expect([...(q('.admin-breadcrumb__list')?.classList ?? [])]).toEqual(expect.arrayContaining(REACH.split(' ')));
        expect(CRUMB_LINK.split(' ')).toEqual(expect.arrayContaining(REACH.split(' ')));
    });
});
