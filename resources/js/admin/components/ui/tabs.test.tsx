import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { REACH } from './button';
import { key, mount, settle, type Mounted } from './harness';
import { CHOSEN } from './segmented';
import { Tab, TabList, TabPanel, Tabs } from './tabs';

/*
 * Tabs: a row of views over one place, drawn as a pill group with the chosen tab filled in the
 * ink. The row is a tablist with a name, each tab carries its choice in aria-selected and not in
 * the fill alone, the arrow keys move between the tabs, and a panel kept mounted stays in the page
 * while another is shown, hidden, so whatever was typed in it is still there on the way back.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = (selector: string) => view.host.querySelector(selector);
const tabs = () => [...view.host.querySelectorAll('[role="tab"]')];

/** A tab chosen as a pointer chooses one: the tabs act on the press itself. */
function choose(tab: Element | null | undefined): void {
    if (tab === null || tab === undefined) {
        throw new Error('No tab to choose.');
    }

    act(() => tab.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0 })));
}

function Harness({ kept = true }: { kept?: boolean }) {
    const [value, setValue] = useState('first');

    return (
        <Tabs value={value} onValueChange={setValue}>
            <TabList label="Views" className="probe-list">
                <Tab value="first" className="probe-tab">
                    First
                </Tab>
                <Tab value="second" className="probe-tab">
                    Second
                </Tab>
            </TabList>
            <TabPanel value="first" forceMount={kept} className="probe-first">
                <input className="probe-draft" defaultValue="" />
            </TabPanel>
            <TabPanel value="second" forceMount={kept} className="probe-second">
                The second view
            </TabPanel>
        </Tabs>
    );
}

describe('the tabs', () => {
    it('draw the row as a named tablist, a pill on the soft tint, each tab keeping the class its caller passes', () => {
        view.render(<Harness />);

        const list = q('.probe-list');

        expect(list?.getAttribute('role')).toBe('tablist');
        expect(list?.getAttribute('aria-label')).toBe('Views');
        expect([...(list?.classList ?? [])]).toEqual(expect.arrayContaining(['rounded-full', 'bg-muted', 'p-1']));
        expect(tabs().every((tab) => tab.classList.contains('probe-tab'))).toBe(true);
    });

    it('fills the chosen tab in the ink and marks it selected, the other neither', () => {
        view.render(<Harness />);

        const [first, second] = tabs();

        expect(first?.getAttribute('aria-selected')).toBe('true');
        expect([...(first?.classList ?? [])]).toEqual(expect.arrayContaining(['bg-foreground', 'text-background']));
        expect(second?.getAttribute('aria-selected')).toBe('false');
        expect(second?.classList.contains('bg-foreground')).toBe(false);

        choose(second);

        expect(tabs()[1]?.getAttribute('aria-selected')).toBe('true');
        expect(tabs()[1]?.classList.contains('bg-foreground')).toBe(true);
        expect(tabs()[0]?.classList.contains('bg-foreground')).toBe(false);
    });

    it('gives each 38px tab the kit\'s 44px reach in the compact layout, and paints the chosen one in the system\'s highlight in forced colours', () => {
        view.render(<Harness />);

        const [first, second] = tabs();

        expect(tabs().every((tab) => REACH.split(' ').every((name) => tab.classList.contains(name)))).toBe(true);
        expect(tabs().every((tab) => tab.classList.contains('h-9.5'))).toBe(true);
        expect([...(first?.classList ?? [])]).toEqual(expect.arrayContaining(CHOSEN.split(' ')));
        expect([...(second?.classList ?? [])].some((name) => name.startsWith('forced-colors:'))).toBe(false);
    });

    it('moves between the tabs on the arrow keys, the tab reached chosen', async () => {
        view.render(<Harness />);

        const [first, second] = tabs() as HTMLElement[];
        first?.focus();
        key(first ?? null, 'ArrowRight');
        await settle();

        expect(document.activeElement).toBe(second);
        expect(second?.getAttribute('aria-selected')).toBe('true');

        key(second ?? null, 'ArrowLeft');
        await settle();

        expect(document.activeElement).toBe(first);
        expect(first?.getAttribute('aria-selected')).toBe('true');
    });

    it('keeps a mounted panel in the page while another is shown, hidden, with what was typed in it', async () => {
        view.render(<Harness />);

        const draft = q('.probe-draft') as HTMLInputElement | null;

        if (draft === null) {
            throw new Error('No draft field.');
        }

        draft.value = 'half a note';
        choose(tabs()[1]);
        await settle();

        expect(q('.probe-first')?.hasAttribute('hidden')).toBe(true);
        expect(q('.probe-second')?.hasAttribute('hidden')).toBe(false);
        expect(q('.probe-first')?.getAttribute('role')).toBe('tabpanel');

        choose(tabs()[0]);
        await settle();

        expect(q('.probe-first')?.hasAttribute('hidden')).toBe(false);
        expect(q('.probe-second')?.hasAttribute('hidden')).toBe(true);
        expect((q('.probe-draft') as HTMLInputElement | null)?.value).toBe('half a note');
    });

    it('takes what a panel not kept mounted holds out of the page while another is shown, leaving its empty frame hidden', async () => {
        view.render(<Harness kept={false} />);

        expect(q('.probe-second')?.hasAttribute('hidden')).toBe(true);
        expect(q('.probe-second')?.textContent).toBe('');

        choose(tabs()[1]);
        await settle();

        expect(q('.probe-draft')).toBeNull();
        expect(q('.probe-first')?.hasAttribute('hidden')).toBe(true);
        expect(q('.probe-second')?.textContent).toBe('The second view');
    });

    it('holds its own choice where its owner gives it none but the first', async () => {
        view.render(
            <Tabs defaultValue="first">
                <TabList label="Views">
                    <Tab value="first">First</Tab>
                    <Tab value="second">Second</Tab>
                </TabList>
                <TabPanel value="first" forceMount className="probe-first">
                    One
                </TabPanel>
                <TabPanel value="second" forceMount className="probe-second">
                    Two
                </TabPanel>
            </Tabs>,
        );

        expect(q('.probe-second')?.hasAttribute('hidden')).toBe(true);

        choose(tabs()[1]);
        await settle();

        expect(tabs()[1]?.classList.contains('bg-foreground')).toBe(true);
        expect(q('.probe-first')?.hasAttribute('hidden')).toBe(true);
        expect(q('.probe-second')?.hasAttribute('hidden')).toBe(false);
    });
});
