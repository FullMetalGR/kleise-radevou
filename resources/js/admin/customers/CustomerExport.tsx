import { Button } from '@/components/ui/button';
import { useDownload } from '@/lib/download';
import { type CustomersKey, useCustomers } from './words';

/*
 * A customer's Export: a secondary button in the page's head, rendered by the page only for a
 * viewer holding manageBookings, that downloads the file the business hands a customer who asks
 * for their data, GET {page}/export, through the export buttons' one road (lib/download.ts):
 * busy while the request is on its way, the JSON file saved under the name the server gave it,
 * the tenant's slug and today, and a failure an error toast. Its words are keys of the customers
 * section.
 */

export const EXPORT_LABEL = 'export.label' satisfies CustomersKey;

export const EXPORT_ERROR = 'export.error' satisfies CustomersKey;

export function CustomerExport({ url }: { url: string }) {
    const words = useCustomers();
    const download = useDownload({ type: 'application/json', fallback: 'customer.json', failure: words.text(EXPORT_ERROR) });

    return (
        <Button className="admin-customer-export self-start" busy={download.busy} busyLabel={words.text('export.busy')} onClick={() => download.start(`${url}/export`)}>
            {words.text(EXPORT_LABEL)}
        </Button>
    );
}
