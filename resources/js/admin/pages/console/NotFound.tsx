import { Head } from '@inertiajs/react';

/*
 * An unknown address on the console's host: the console's own 404 page inside its shell, as the
 * admin's is inside the admin's, not a blank panel. Only a signed-in operator sees it; anyone
 * else is sent to the door first.
 */
export default function NotFound() {
    return (
        <>
            <Head title="Page not found" />
            <h1 className="text-2xl font-semibold">Page not found</h1>
            <p className="mt-2 text-muted-foreground">There is nothing at this address on the console.</p>
        </>
    );
}
