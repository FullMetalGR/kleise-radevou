<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlements;
use App\Platform\FreeTenants;
use App\Site\DemoSalon;
use App\Site\SiteLanguage;
use App\Tenancy\BusinessCategory;
use App\Tenancy\Signup;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Connection;

/**
 * The demo tenant's rebuild: the one path that takes the reserved demo label. It builds the
 * demo from nothing whether or not it exists, through the provisioner's rebuild, as a free
 * tenant: every feature, Europe/Athens, EUR, the category hair_salon, in Greek, true to the
 * salon it stands for; and its owner under an address on a reserved domain with a random
 * password drawn here and handed to the model's hashed cast and nowhere else, so nobody is
 * shown it and nobody signs in. The seed is DemoSalon's, run inside the build's transaction.
 * Nothing is sent: provision's path mails no welcome, the seed publishes nothing, and every
 * email switch of the tenant is off.
 *
 * Scheduled nightly at 04:00 Europe/Athens in routes/console.php, without overlapping, and run
 * by hand after a deploy that needs the demo back.
 */
final class ResetDemoCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Drop and rebuild the demo tenant under the reserved demo label: a free hair salon with a week of sample bookings relative to today. Scheduled nightly at 04:00 Europe/Athens.';

    public function __construct(
        #[Config('site.demo_slug')]
        private readonly string $demoSlug,
    ) {
        parent::__construct();
    }

    public function handle(TenantProvisioner $provisioner, DemoSalon $salon): int
    {
        $signup = new Signup(
            slug: $this->demoSlug,
            name: DemoSalon::NAME,
            timezone: new DateTimeZone(DemoSalon::ZONE),
            currency: DemoSalon::CURRENCY,
            ownerName: DemoSalon::OWNER_NAME,
            ownerEmail: DemoSalon::OWNER_EMAIL,
            ownerPassword: bin2hex(random_bytes(FreeTenants::SECRET_BYTES)),
            entitlements: Entitlements::all(),
            category: BusinessCategory::HairSalon->value,
            locale: SiteLanguage::Greek,
        );

        $planted = ['stylists' => 0, 'services' => 0, 'bookings' => 0];

        $tenant = $provisioner->rebuildDemo($signup, function (Tenant $tenant, Connection $db) use ($salon, &$planted): void {
            $planted = $salon->plant($tenant, $db);
        });

        $this->info(sprintf(
            'Demo tenant "%s" rebuilt: %d stylists, %d services and %d bookings.',
            $tenant->slug,
            $planted['stylists'],
            $planted['services'],
            $planted['bookings'],
        ));

        return self::SUCCESS;
    }
}
