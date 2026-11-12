<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Db\SettingsRecords;
use App\Infrastructure\Map\GoogleMapsKey;
use App\Jobs\PrepareMap;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * The business page's map for every row that holds an address and no ready map (a map on every
 * profile). Such a row is in none, its address saved before the map existed or while the key was
 * unset; in failed, its job having found no map or thrown, where a save that changes no address
 * column would leave it failed; in pending with no job coming, a throw having left it before this
 * build settled every throw, its job's re-read having thrown past the queue's retries, or its
 * failed settle having been refused too; or ready naming no image, which only a hand's write
 * leaves. This moves it as a save of the address would, to pending under the row's lock, and
 * queues PrepareMap for the tenant once that has committed, so the job and everything it settles
 * are the save's own road. A row whose map stands ready, or which holds no address, is left.
 *
 * Every tenant whose row is not provisioning is read, as tenant:migrate reads them, a suspended or
 * a closing one included: its page is served again on a reopening, and a map costs it nothing. A
 * row still provisioning has no schema to read, nor has a closed one, whose schema the drop
 * removed: the set is Tenant::built(), the one every tenant loop reads.
 *
 * A second run leaves every row as the first left it. A map the first run's jobs made is skipped;
 * a row they left failed is queued again, its job asking Google again and failing the same where
 * nothing changed; a row still pending, its first job not yet run, is queued again, and the job
 * that runs second finds the row settled and exits on its re-read. While the key is unset nothing
 * is written and the run says why: the job would ask the geocoder with no key, which answers no
 * point, and settle every row it took failed.
 *
 * Run by hand, once, at the deploy that brings it, and scheduled nowhere.
 */
final class BackfillMapsCommand extends Command
{
    public const string QUEUED = 'Queued the map for %d %s.';

    public const string NO_KEY = 'The Google Maps key is unset, so no map can be made; nothing was queued.';

    protected $signature = 'map:backfill';

    protected $description = 'Queue the business page\'s map for every built tenant whose settings hold an address and no ready map, in none, failed, pending or ready naming no image, as a save of the address would. Prints the count queued. Safe to run twice.';

    public function handle(SettingsRecords $records, GoogleMapsKey $key): int
    {
        if (! $key->isSet()) {
            $this->error(self::NO_KEY);

            return self::INVALID;
        }

        $queued = 0;

        foreach (Tenant::built()->get() as $tenant) {
            if ($records->markMapPending($tenant)) {
                PrepareMap::dispatch($tenant->id);
                $queued++;
            }
        }

        $this->info(sprintf(self::QUEUED, $queued, $queued === 1 ? 'tenant' : 'tenants'));

        return self::SUCCESS;
    }
}
