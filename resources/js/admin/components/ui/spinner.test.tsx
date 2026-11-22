import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { Spinner } from './spinner';

/*
 * The one spinner of the admin: a ring of the current ink with one quarter left out, turning only
 * where the person has not asked for less motion. With a label it is a status a screen reader
 * announces by that label; without one it is decoration beside words that already say what is
 * loading, hidden from assistive technology. The button keeps its own spinner inside its label,
 * and nothing else in the sources spells a turning ring by hand.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const spinner = () => view.host.querySelector('.admin-spinner');
const classes = () => [...(spinner()?.classList ?? [])];

/** The turning utility, put together here so this file does not spell it whole. */
const TURN = ['animate', 'spin'].join('-');

const RESOURCES = join(process.cwd(), 'resources');

/** Every file under the folder given whose text could spell a utility class. */
function filesUnder(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return filesUnder(path);
        }

        return /\.(tsx?|jsx?|css|php)$/.test(name) ? [path] : [];
    });
}

describe('the spinner', () => {
    it('is a 24 pixel ring of the current ink with its top left open, turning only under the motion the person allows', () => {
        view.render(<Spinner label="Loading" />);

        expect(spinner()?.tagName).toBe('SPAN');
        expect(classes()).toEqual(expect.arrayContaining(['size-6', 'shrink-0', 'rounded-full', 'border-2', 'border-current', 'border-t-transparent', `motion-safe:${TURN}`]));
        expect(classes()).not.toContain(TURN);
    });

    it('is 14 pixels inline, set in a line of words', () => {
        view.render(<Spinner size="inline" />);

        expect(classes()).toEqual(expect.arrayContaining(['size-3.5', 'inline-block', 'border-2', `motion-safe:${TURN}`]));
        expect(classes()).not.toContain('size-6');
    });

    it('is a status named by its label where it has one', () => {
        view.render(<Spinner label="Loading the list" />);

        expect(spinner()?.getAttribute('role')).toBe('status');
        expect(spinner()?.getAttribute('aria-label')).toBe('Loading the list');
        expect(spinner()?.hasAttribute('aria-hidden')).toBe(false);
    });

    it('is hidden from assistive technology and holds no role where it has no label', () => {
        view.render(<Spinner />);

        expect(spinner()?.getAttribute('aria-hidden')).toBe('true');
        expect(spinner()?.hasAttribute('role')).toBe(false);
        expect(spinner()?.hasAttribute('aria-label')).toBe(false);
    });

    it('takes the place\'s own classes beside its own', () => {
        view.render(<Spinner size="inline" className="probe mr-2" />);

        expect(classes()).toEqual(expect.arrayContaining(['admin-spinner', 'probe', 'mr-2', 'size-3.5']));
    });

    it('is the one turning ring spelled in the sources, beside the button\'s own', () => {
        const spelled = filesUnder(RESOURCES)
            .filter((path) => readFileSync(path, 'utf8').includes(TURN))
            .map((path) => relative(RESOURCES, path).split('\\').join('/'))
            .sort();

        expect(spelled).toEqual(['js/admin/components/ui/button.tsx', 'js/admin/components/ui/spinner.tsx']);
    });
});
