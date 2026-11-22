import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import NotFound from './NotFound';

/*
 * The console's 404 page, GET /{path}: what any other address on the console's host answers
 * an operator. The shell around it is the layouts' test.
 */

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => mounted.unmount());

describe('the console\'s 404 page', () => {
    it('says there is nothing at the address', () => {
        mounted.render(<NotFound />);

        expect(mounted.host.querySelector('h1')?.textContent).toBe('Page not found');
        expect(mounted.host.textContent).toContain('There is nothing at this address on the console.');
    });

    it('says it as the page header\'s lead and holds no control, the menu beside it being the way on', () => {
        mounted.render(<NotFound />);

        expect(mounted.host.querySelector('.admin-page-header .admin-page-header__lead')?.textContent).toBe('There is nothing at this address on the console.');
        expect(mounted.host.querySelectorAll('p')).toHaveLength(1);
        expect(mounted.host.querySelector('a, button, input, select, textarea, [role="button"], [tabindex]')).toBeNull();
    });
});
