import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { PageHeader } from './page-header';

/*
 * The one header every screen inside the shell opens with: a row holding the page's title, its
 * optional count and status beside the title, and the page's own buttons last; an optional
 * breadcrumb above the row and an optional lead line under it. The title is the page's one main
 * heading, at the page size and the smaller one below the wide breakpoint, and it may shrink and
 * wrap inside the row so a long one never pushes the buttons off the screen.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = (selector: string) => view.host.querySelector(selector);
const classesOf = (element: Element | null | undefined) => [...(element?.classList ?? [])];

describe('the page header', () => {
    it('is a header whose row holds the title as the only main heading, at the page size and the compact size below the wide breakpoint', () => {
        view.render(<PageHeader title="title-token" />);

        const root = q('header.admin-page-header');
        const row = root?.querySelector(':scope > .admin-page-header__row');
        const headings = view.host.querySelectorAll('h1');

        expect(root).not.toBeNull();
        expect(row).not.toBeNull();
        expect(headings).toHaveLength(1);
        expect(headings[0]?.parentElement).toBe(row);
        expect(headings[0]?.textContent).toBe('title-token');
        expect(classesOf(headings[0])).toEqual(expect.arrayContaining(['admin-page-title', 'text-page-title', 'max-wide:text-page-title-compact', 'min-w-0']));
    });

    it('wraps its row, so the buttons drop under a long title, and gives the row the header\'s room above and below', () => {
        view.render(<PageHeader title="title-token" actions={<button type="button">action-token</button>} />);

        expect(classesOf(q('.admin-page-header__row'))).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'pt-5.5', 'pb-4.5']));
    });

    it('sets the count and the status beside the title, never inside it, in that order', () => {
        view.render(<PageHeader title="title-token" count="count-token" status={<span className="probe-status">status-token</span>} />);

        const row = q('.admin-page-header__row');
        const title = q('h1');

        expect([...(row?.children ?? [])].map((child) => child.textContent)).toEqual(['title-token', 'count-token', 'status-token']);
        expect(q('.admin-page-header__count')?.parentElement).toBe(row);
        expect(q('.probe-status')?.parentElement).toBe(row);
        expect(title?.textContent).toBe('title-token');
        expect(title?.querySelector('.admin-page-header__count, .probe-status')).toBeNull();
    });

    it('holds the page\'s own buttons last in the row, and no holder where the page has none', () => {
        view.render(<PageHeader title="title-token" count="count-token" actions={<button type="button">action-token</button>} />);

        const row = q('.admin-page-header__row');
        const actions = q('.admin-page-header__actions');

        expect(actions?.parentElement).toBe(row);
        expect(row?.lastElementChild).toBe(actions);
        expect(actions?.querySelector('button')?.textContent).toBe('action-token');
        expect(classesOf(actions)).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'ms-auto']));

        view.render(<PageHeader key="bare" title="title-token" />);

        expect(q('.admin-page-header__actions')).toBeNull();
        expect(q('.admin-page-header__count')).toBeNull();
    });

    it('puts the lead under the row in the muted ink, kept to a readable measure', () => {
        view.render(<PageHeader title="title-token" lead="lead-token" />);

        const lead = q('p.admin-page-header__lead');

        expect(lead?.textContent).toBe('lead-token');
        expect(lead?.previousElementSibling).toBe(q('.admin-page-header__row'));
        expect(classesOf(lead)).toEqual(expect.arrayContaining(['text-muted-foreground', 'max-w-[78ch]', 'wrap-break-word']));

        view.render(<PageHeader key="bare" title="title-token" />);

        expect(q('.admin-page-header__lead')).toBeNull();
    });

    it('sets the breadcrumb above the row', () => {
        view.render(<PageHeader title="title-token" breadcrumb={<nav className="probe-crumb">crumb-token</nav>} />);

        const root = q('header.admin-page-header');

        expect(root?.firstElementChild).toBe(q('.probe-crumb'));
        expect(q('.probe-crumb')?.nextElementSibling).toBe(q('.admin-page-header__row'));
    });

    it('keeps the hook class of the screen that places it', () => {
        view.render(<PageHeader title="title-token" className="admin-probe-head" />);

        expect(classesOf(q('header.admin-page-header'))).toContain('admin-probe-head');
    });
});
