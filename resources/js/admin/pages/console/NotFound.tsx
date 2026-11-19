import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';

/*
 * An unknown address on the console's host: the console's own 404 page inside its shell, as the
 * admin's is inside the admin's, not a blank panel. Only a signed-in operator sees it; anyone
 * else is sent to the door first.
 */
export default function NotFound() {
    return (
        <>
            <Head title="Page not found" />
            <PageHeader title="Page not found" lead="There is nothing at this address on the console." />
        </>
    );
}
