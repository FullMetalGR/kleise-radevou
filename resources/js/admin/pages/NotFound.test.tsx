import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import { keyedShell, WithShell } from '@/testing/keyed';
import NotFound, { NOT_FOUND_HEADING } from './NotFound';

/*
 * The admin's 404 page inside the shell: its heading and its sentence are the shell's not_found
 * group, rendered with the keyed table. The shell around it is the layouts' test, and the 404's
 * status the feature suite's.
 */

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

const shell = keyedShell();

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => mounted.unmount());

describe('the admin\'s 404 page', () => {
    it('heads the page and says there is nothing at the address, in the shell\'s words', () => {
        mounted.render(
            <WithShell>
                <NotFound />
            </WithShell>,
        );

        expect(mounted.host.querySelector('h1')?.textContent).toBe(shell.text(NOT_FOUND_HEADING));
        expect(mounted.host.querySelector('p')?.textContent).toBe(shell.text('not_found.sentence'));
    });
});
