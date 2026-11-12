import { Button } from '@/components/ui/button';
import { useDownload } from '@/lib/download';
import { customersCsvUrl } from './model';
import { useCustomers } from './words';

/*
 * The Customers list's Download CSV: a secondary button beside the search, that downloads what
 * the list shows under the search the screen answered, GET /admin/customers/csv with that search
 * and never the page, through the export buttons' one road (lib/download.ts): busy while the
 * request is on its way, the file saved under the name the server gave it, a failure an error
 * toast. Its words are keys of the customers section.
 */
export function CustomersDownload({ search }: { search: string }) {
    const words = useCustomers();
    const download = useDownload({ type: 'text/csv', fallback: 'customers.csv', failure: words.text('csv.download_error') });

    return (
        <Button className="admin-customers-download" busy={download.busy} busyLabel={words.text('csv.downloading')} onClick={() => download.start(customersCsvUrl(search))}>
            {words.text('csv.download')}
        </Button>
    );
}
