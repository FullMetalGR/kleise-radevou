import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { EmptyState } from './empty-state';
import { mount, type Mounted } from './harness';

/*
 * The line an empty list shows in place of its rows: a small icon over the sentence, the action
 * under it where there is one, centred in the room the list would take, in the quieter ink. It
 * adds no hook class: each place keeps its own.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const root = () => view.host.querySelector('.probe');

describe('the empty state', () => {
    it('centres its words in the quieter ink with room round them, beside the classes its caller passes', () => {
        view.render(<EmptyState className="probe admin-list-empty">No rows yet.</EmptyState>);

        expect([...(root()?.classList ?? [])]).toEqual(expect.arrayContaining(['probe', 'admin-list-empty', 'text-center', 'text-muted-foreground', 'px-5', 'py-14']));
        expect(root()?.textContent).toBe('No rows yet.');
    });

    it('draws its icon at 28px, hidden from assistive technology', () => {
        view.render(
            <EmptyState className="probe" icon={<svg className="probe-icon" />}>
                No rows yet.
            </EmptyState>,
        );

        const icon = root()?.querySelector('.probe-icon');
        const frame = icon?.parentElement;

        expect(frame?.getAttribute('aria-hidden')).toBe('true');
        expect(frame?.classList.contains('*:size-7')).toBe(true);
        expect(frame?.parentElement).toBe(root());
    });

    it('leaves the icon out where none is given', () => {
        view.render(<EmptyState className="probe">No rows yet.</EmptyState>);

        expect(root()?.querySelector('[aria-hidden="true"]')).toBeNull();
    });

    it('puts the icon first, then the sentence, then the action', () => {
        view.render(
            <EmptyState className="probe" icon={<svg className="probe-icon" />} action={<button type="button">Clear</button>}>
                <p className="probe-sentence">No rows match.</p>
            </EmptyState>,
        );

        const icon = root()?.querySelector('.probe-icon') ?? null;
        const sentence = root()?.querySelector('.probe-sentence') ?? null;
        const action = root()?.querySelector('button') ?? null;

        expect(icon !== null && sentence !== null && action !== null).toBe(true);
        expect(icon?.compareDocumentPosition(sentence as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(sentence?.compareDocumentPosition(action as Node)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(root()?.textContent).toBe('No rows match.Clear');
    });
});
