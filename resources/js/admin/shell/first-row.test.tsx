import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { type FirstRow, FirstRowProvider, useFirstRow } from './first-row';

/*
 * What the shell hands the page's first row: its start, its controls and its own button. Outside
 * the shell the row is handed nothing, so a page header rendered alone is the page's parts alone.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

let seen: FirstRow | null = null;

function Probe() {
    seen = useFirstRow();

    return null;
}

describe('the first row\'s parts', () => {
    it('are nothing outside the shell', () => {
        view.render(<Probe />);

        expect(seen).toEqual({ start: null, controls: null, action: null });
    });

    it('are what the shell hands down inside it', () => {
        const action = (quiet: boolean) => <button type="button">{quiet ? 'quiet' : 'loud'}</button>;
        const value: FirstRow = { start: <span>start</span>, controls: <span>controls</span>, action };

        view.render(
            <FirstRowProvider value={value}>
                <Probe />
            </FirstRowProvider>,
        );

        expect(seen).toBe(value);
    });
});
