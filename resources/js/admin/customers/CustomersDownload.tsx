import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useDownload } from '@/lib/download';
import { cn } from '@/lib/utils';
import { customersCsvUrl } from './model';
import { useCustomers } from './words';

/*
 * The Customers list's Download CSV: a secondary button with a download icon before its words,
 * last in the list's toolbar, that downloads what the list shows under the search the screen
 * answered, GET /admin/customers/csv with that search and never the page, through the export
 * buttons' one road (lib/download.ts): busy while the request is on its way, the file saved under
 * the name the server gave it, a failure an error toast. Its words are keys of the customers
 * section; the toolbar places it through its class.
 */
export function CustomersDownload({ search, className }: { search: string; className?: string }) {
    const words = useCustomers();
    const download = useDownload({ type: 'text/csv', fallback: 'customers.csv', failure: words.text('csv.download_error') });

    return (
        <Button
            className={cn('admin-customers-download', className)}
            icon={<Download />}
            busy={download.busy}
            busyLabel={words.text('csv.downloading')}
            onClick={() => download.start(customersCsvUrl(search))}
        >
            {words.text('csv.download')}
        </Button>
    );
}
