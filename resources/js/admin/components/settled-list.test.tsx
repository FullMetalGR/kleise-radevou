import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from './ui/harness';
import { ListDetail, listGate, resolve, SettledList } from './settled-list';

/*
 * The empty-state gate, settledness first: the mid-load case is proven here because a browser
 * cannot be stopped mid-load honestly.
 */

/** The words a screen hands the kit, neutral tokens: the kit shows each as it was given.
 * */
const LIST_ERROR = 'the-list-error-sentence';

const LOADING = 'the-loading-label';

const DETAIL_ERROR = 'the-detail-error-sentence';

const NOT_FOUND = 'the-not-found-sentence';

const BACK = 'the-back-label';

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

function render(count: number, loading: boolean, error: boolean) {
    view.render(
        <SettledList count={count} state={{ loading, error }} errorSentence={LIST_ERROR} empty={<p className="empty">the-empty-panel</p>}>
            <table className="content" />
        </SettledList>,
    );
}

describe('the settledness gate', () => {
    it('renders neither the empty panel nor a spinner while the list is in flight with no rows', () => {
        render(0, true, false);

        expect(view.host.innerHTML).toBe('');
    });

    it('renders the error notice alone when the list errored with no rows', () => {
        render(0, false, true);

        expect(view.host.querySelector('[role="alert"]')?.textContent).toBe(LIST_ERROR);
        expect(view.host.querySelector('.empty')).toBeNull();
        expect(view.host.querySelector('.content')).toBeNull();
    });

    it('renders the empty panel only once the list has settled with no rows', () => {
        render(0, false, false);

        expect(view.host.querySelector('.empty')).not.toBeNull();
        expect(view.host.querySelector('[role="alert"]')).toBeNull();
    });

    it('renders the content and nothing else whenever there are rows, in flight or not', () => {
        render(3, true, false);

        expect(view.host.querySelector('.content')).not.toBeNull();
        expect(view.host.querySelector('.empty')).toBeNull();
        expect(listGate(3, { loading: false, error: true })).toBe('content');
        expect(listGate(0, { loading: true, error: true })).toBe('nothing');
    });
});

type Row = { id: number; name: string };

const ROWS: Row[] = [
    { id: 1, name: 'Haircut' },
    { id: 2, name: 'Colour' },
];

function renderDetail(id: number | 'new', loading: boolean, error: boolean, onBack = vi.fn()) {
    view.render(
        <ListDetail<Row>
            rows={loading || error ? [] : ROWS}
            state={{ loading, error }}
            id={id}
            loadingLabel={LOADING}
            errorSentence={DETAIL_ERROR}
            notFoundSentence={NOT_FOUND}
            backLabel={BACK}
            listHref="/admin/services"
            onBack={onBack}
            create={<form className="create" />}
        >
            {(row) => <article className="found">{row.name}</article>}
        </ListDetail>,
    );

    return onBack;
}

describe('the list-detail scaffold', () => {
    it('resolves in strict precedence: loading, then error, then notFound once the list settled without the id, then found', () => {
        const state = (loading: boolean, error: boolean) => ({ loading, error });
        const byId = (id: number) => (row: Row) => row.id === id;

        expect(resolve(ROWS, state(true, true), byId(1))).toEqual({ kind: 'loading' });
        expect(resolve(ROWS, state(false, true), byId(1))).toEqual({ kind: 'error' });
        expect(resolve([], state(true, false), byId(1))).toEqual({ kind: 'loading' });
        expect(resolve(ROWS, state(false, false), byId(3))).toEqual({ kind: 'notFound' });
        expect(resolve(ROWS, state(false, false), byId(2))).toEqual({ kind: 'found', row: ROWS[1] });
    });

    it('renders loading as a spinner alone', () => {
        renderDetail(1, true, false);

        expect(view.host.children).toHaveLength(1);
        expect(view.host.querySelector('.admin-spinner[role="status"]')?.getAttribute('aria-label')).toBe(LOADING);
        expect(view.host.querySelector('.found, .create, [role="alert"]')).toBeNull();
    });

    it('renders error as a non-dismissible error notice in the screen\'s own sentence', () => {
        renderDetail(1, false, true);

        const notice = view.host.querySelector('.admin-detail-error');

        expect(notice?.getAttribute('role')).toBe('alert');
        expect(notice?.textContent).toBe(DETAIL_ERROR);
        expect(view.host.querySelector('.admin-notice__dismiss')).toBeNull();
        expect(view.host.querySelector('.admin-spinner')).toBeNull();
    });

    it('renders notFound as the Back link over a non-dismissible warning notice, the link taking the page\'s own way back', () => {
        const onBack = renderDetail(3, false, false);

        const back = view.host.querySelector('.admin-detail-back');
        const notice = view.host.querySelector('.admin-detail-missing__notice');

        expect(back?.textContent).toBe(BACK);
        expect(back?.getAttribute('href')).toBe('/admin/services');
        expect(back !== null && notice !== null && (back.compareDocumentPosition(notice) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0).toBe(true);
        expect(notice?.getAttribute('role')).toBe('status');
        expect(notice?.textContent).toBe(NOT_FOUND);
        expect(view.host.querySelector('.admin-notice__dismiss')).toBeNull();

        press(back);
        expect(onBack).toHaveBeenCalledTimes(1);
    });

    it('renders found as the content', () => {
        renderDetail(2, false, false);

        expect(view.host.querySelector('.found')?.textContent).toBe('Colour');
        expect(view.host.querySelector('.admin-spinner, [role="alert"], .admin-detail-back')).toBeNull();
    });

    it('branches a create route before the ladder: the form renders with the list still loading, and with it errored', () => {
        renderDetail('new', true, false);
        expect(view.host.querySelector('.create')).not.toBeNull();
        expect(view.host.querySelector('.admin-spinner')).toBeNull();

        renderDetail('new', false, true);
        expect(view.host.querySelector('.create')).not.toBeNull();
        expect(view.host.querySelector('[role="alert"]')).toBeNull();
    });
});
