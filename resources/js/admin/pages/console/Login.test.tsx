import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted } from '@/components/ui/harness';
import Login from './Login';

/*
 * The console's door, as it names itself: the console's wordmark, the brand prop's public name
 * with the word console after it, above the admin's sign-in form. The form's round trip is the
 * feature and browser suites'.
 */

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: () => ({
        data: { email: '', password: '' },
        errors: {},
        processing: false,
        setData: () => {},
        post: () => {},
        reset: () => {},
    }),
}));

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => {
    mounted.unmount();
});

describe('the console\'s door', () => {
    it('names the console by the wordmark of the brand prop\'s name, above the sign-in form', () => {
        mounted.render(<Login refusal={null} brand={{ name: 'Example Bookings' }} />);

        const name = mounted.host.querySelector('.admin-console-login-name');

        expect(name?.querySelector('.brand-wordmark .brand-wordmark__name')?.textContent).toBe('Example Bookings');
        expect(name?.textContent).toBe('Example Bookings console');
        expect(mounted.host.querySelector('h1')?.textContent).toBe('Sign in');
        expect(mounted.host.querySelector('.admin-console-login-submit')).not.toBeNull();
    });
});
