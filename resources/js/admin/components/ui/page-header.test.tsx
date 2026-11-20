import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FirstRowProvider } from '@/shell/first-row';
import { mount, type Mounted } from './harness';
import { PageHeader } from './page-header';

/*
 * The one header every screen inside the shell opens with: a row holding the page's title, its
 * optional count and status beside the title, and the page's own buttons last; an optional
 * breadcrumb above the row and an optional lead line under it. The title is the page's one main
 * heading, at the page size and the smaller one below the wide breakpoint, and it may shrink and
 * wrap inside the row so a long one never pushes the buttons off the screen.
 *
 * Inside the shell the row is the page's first row and carries the shell's parts as well, handed
 * down by the shell: the menu's button at the start where the shell is compact, the controls
 * after the title, and the shell's own button after the page's, quiet where the page has a main
 * button of its own.
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

    it('holds the page\'s own buttons last in the row, at its far end, and no holder where the page has none', () => {
        view.render(<PageHeader title="title-token" count="count-token" actions={<button type="button">action-token</button>} />);

        const row = q('.admin-page-header__row');
        const end = q('.admin-page-header__end');
        const actions = q('.admin-page-header__actions');

        expect(end?.parentElement).toBe(row);
        expect(row?.lastElementChild).toBe(end);
        expect(end?.lastElementChild).toBe(actions);
        expect(actions?.querySelector('button')?.textContent).toBe('action-token');
        expect(classesOf(end)).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'ms-auto', 'min-w-0']));
        expect(classesOf(actions)).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'min-w-0']));

        view.render(<PageHeader key="bare" title="title-token" />);

        expect(q('.admin-page-header__actions')).toBeNull();
        expect(q('.admin-page-header__end')).toBeNull();
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

describe('the page header as the shell\'s first row', () => {
    const START = <button type="button" data-part="start">start-token</button>;
    const CONTROLS = <div data-part="controls">controls-token</div>;
    const ACTIONS = <button type="button" data-part="actions">actions-token</button>;

    function shellAction(quiet: boolean) {
        return (
            <button type="button" data-part="action" data-quiet={String(quiet)}>
                action-token
            </button>
        );
    }

    const parts = () => [...view.host.querySelectorAll('h1, [data-part]')].map((part) => part.getAttribute('data-part') ?? 'title');

    it('keeps the old top bar\'s hook on the row, inside the shell and out', () => {
        view.render(<PageHeader title="title-token" />);
        expect(classesOf(q('.admin-page-header__row'))).toContain('admin-topbar');

        view.render(
            <FirstRowProvider key="shell" value={{ start: null, controls: CONTROLS, action: shellAction }}>
                <PageHeader title="title-token" />
            </FirstRowProvider>,
        );
        expect(classesOf(q('.admin-page-header__row'))).toContain('admin-topbar');
    });

    it('holds, in order, the shell\'s start, the title, the controls, the page\'s own buttons and the shell\'s button', () => {
        view.render(
            <FirstRowProvider value={{ start: START, controls: CONTROLS, action: shellAction }}>
                <PageHeader title="title-token" count="count-token" actions={ACTIONS} />
            </FirstRowProvider>,
        );

        const row = q('.admin-page-header__row');
        const end = q('.admin-page-header__end');

        expect(parts()).toEqual(['start', 'title', 'controls', 'actions', 'action']);
        expect(row?.firstElementChild).toBe(q('[data-part="start"]'));
        expect(row?.lastElementChild).toBe(end);
        expect(end?.lastElementChild).toBe(q('[data-part="action"]'));
        expect(q('[data-part="actions"]')?.parentElement).toBe(q('.admin-page-header__actions'));
    });

    it('asks for the shell\'s button quiet where the page has buttons of its own, and loud where it has none', () => {
        const action = vi.fn(shellAction);

        view.render(
            <FirstRowProvider value={{ start: null, controls: CONTROLS, action }}>
                <PageHeader title="title-token" actions={ACTIONS} />
            </FirstRowProvider>,
        );
        expect(action).toHaveBeenLastCalledWith(true);
        expect(q('[data-part="action"]')?.getAttribute('data-quiet')).toBe('true');

        view.render(
            <FirstRowProvider key="bare" value={{ start: null, controls: CONTROLS, action }}>
                <PageHeader title="title-token" />
            </FirstRowProvider>,
        );
        expect(action).toHaveBeenLastCalledWith(false);
        expect(parts()).toEqual(['title', 'controls', 'action']);
    });

    it('lets a page say how its own buttons stand, the page\'s word winning over the guess', () => {
        view.render(
            <FirstRowProvider value={{ start: null, controls: null, action: shellAction }}>
                <PageHeader title="title-token" actions={ACTIONS} quiet={false} />
            </FirstRowProvider>,
        );
        expect(q('[data-part="action"]')?.getAttribute('data-quiet')).toBe('false');

        view.render(
            <FirstRowProvider key="quiet" value={{ start: null, controls: null, action: shellAction }}>
                <PageHeader title="title-token" quiet />
            </FirstRowProvider>,
        );
        expect(q('[data-part="action"]')?.getAttribute('data-quiet')).toBe('true');
    });

    it('holds nothing of the shell\'s outside it', () => {
        view.render(<PageHeader title="title-token" actions={ACTIONS} />);

        expect(parts()).toEqual(['title', 'actions']);
    });
});
