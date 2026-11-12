<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Tenancy\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The demo catalog on the two demo tenants, once DemoTenantsSeeder has provisioned them.
 *
 * luna gets two named staff members beside its implicit row, four active services whose
 * assignments exercise different shapes, one on both people, one on one person, one with a
 * processing gap, one requiring approval, and one inactive service that must never reach the
 * wire. pier's services sit on its implicit member alone, so its public staff lists are empty
 * and the booking app skips its staff step.
 *
 * Every row is named, and a row is inserted only where no row of that name exists, so a second
 * run changes nothing, not even a timestamp. Every table is named by schema with no search
 * path change, the way the page and the catalog read. Each service takes the tenant's currency,
 * as a service created through the admin will.
 */
final class DemoCatalogSeeder extends Seeder
{
    /**
     * Per tenant slug: the named staff members in sort order, and the services in sort order,
     * each naming the staff members that perform it. A null in a service's staff list is the
     * tenant's implicit member. Every other column takes the table's default.
     *
     * @var array<string, array{staff: list<string>, services: list<array{name: string, description: string, duration_min: int, price_minor: int, payment_mode: string, status: string, staff: list<string|null>, processing_gap_min?: int, requires_approval?: bool}>}>
     */
    public const array CATALOG = [
        'luna' => [
            'staff' => ['Elena Papadaki', 'Nikos Georgiou'],
            'services' => [
                [
                    'name' => 'Haircut',
                    'description' => 'Consultation, wash, cut and finish.',
                    'duration_min' => 45,
                    'price_minor' => 3500,
                    'payment_mode' => 'onsite',
                    'status' => 'active',
                    'staff' => ['Elena Papadaki', 'Nikos Georgiou'],
                ],
                [
                    'name' => 'Beard trim',
                    'description' => 'Shape and trim, finished with a hot towel.',
                    'duration_min' => 20,
                    'price_minor' => 1500,
                    'payment_mode' => 'onsite',
                    'status' => 'active',
                    'staff' => ['Nikos Georgiou'],
                ],
                [
                    'name' => 'Colour',
                    'description' => 'Full-head colour. The colour develops for half an hour before the finish.',
                    'duration_min' => 60,
                    'processing_gap_min' => 30,
                    'price_minor' => 8000,
                    'payment_mode' => 'online',
                    'status' => 'active',
                    'staff' => ['Elena Papadaki', 'Nikos Georgiou'],
                ],
                [
                    'name' => 'Bridal trial',
                    'description' => 'A trial run of the wedding-day style. We confirm each request by hand.',
                    'duration_min' => 120,
                    'price_minor' => 15000,
                    'payment_mode' => 'online',
                    'requires_approval' => true,
                    'status' => 'active',
                    'staff' => ['Elena Papadaki'],
                ],
                [
                    'name' => 'Perm',
                    'description' => 'Not offered this season.',
                    'duration_min' => 150,
                    'price_minor' => 9000,
                    'payment_mode' => 'onsite',
                    'status' => 'inactive',
                    'staff' => ['Elena Papadaki', 'Nikos Georgiou'],
                ],
            ],
        ],
        'pier' => [
            'staff' => [],
            'services' => [
                [
                    'name' => 'Initial assessment',
                    'description' => 'A first appointment: history, examination and a treatment plan.',
                    'duration_min' => 60,
                    'price_minor' => 6500,
                    'payment_mode' => 'onsite',
                    'status' => 'active',
                    'staff' => [null],
                ],
                [
                    'name' => 'Follow-up session',
                    'description' => 'Treatment and exercises, building on the plan.',
                    'duration_min' => 30,
                    'price_minor' => 4500,
                    'payment_mode' => 'onsite',
                    'status' => 'active',
                    'staff' => [null],
                ],
            ],
        ],
    ];

    /**
     * @var array{staff: int, services: int, assignments: int}
     */
    private array $added = ['staff' => 0, 'services' => 0, 'assignments' => 0];

    public function run(): void
    {
        foreach (self::CATALOG as $slug => $catalog) {
            $schema = Slug::of($slug)->schemaName();
            $this->added = ['staff' => 0, 'services' => 0, 'assignments' => 0];

            $staffIds = [];

            foreach ($catalog['staff'] as $position => $name) {
                $staffIds[$name] = $this->staffMember($schema, $name, $position + 1);
            }

            foreach ($catalog['services'] as $position => $service) {
                $serviceId = $this->service($schema, $this->currencyOf($slug), $service, $position + 1);

                foreach ($service['staff'] as $name) {
                    $staffId = $name === null
                        ? $this->implicitStaffId($schema)
                        : $staffIds[$name] ?? throw new LogicException(sprintf('The catalog of "%s" names no staff member "%s".', $slug, $name));

                    $this->added['assignments'] += DB::table($schema.'.service_staff')
                        ->insertOrIgnore(['service_id' => $serviceId, 'staff_id' => $staffId]);
                }
            }

            $this->command->line($this->added === ['staff' => 0, 'services' => 0, 'assignments' => 0]
                ? sprintf('Catalog of "%s" is already in place; nothing changed.', $slug)
                : sprintf('Catalog of "%s": %d staff, %d services and %d assignments added.', $slug, $this->added['staff'], $this->added['services'], $this->added['assignments']));
        }
    }

    private function staffMember(string $schema, string $name, int $sort): int
    {
        $existing = DB::table($schema.'.staff')->where('name', $name)->value('id');

        if (is_int($existing)) {
            return $existing;
        }

        $this->added['staff']++;

        return DB::table($schema.'.staff')->insertGetId(['name' => $name, 'status' => 'active', 'sort' => $sort]);
    }

    /**
     * @param  array{name: string, description: string, duration_min: int, price_minor: int, payment_mode: string, status: string, staff: list<string|null>, processing_gap_min?: int, requires_approval?: bool}  $service
     */
    private function service(string $schema, string $currency, array $service, int $sort): int
    {
        $existing = DB::table($schema.'.services')->where('name', $service['name'])->value('id');

        if (is_int($existing)) {
            return $existing;
        }

        $this->added['services']++;

        return DB::table($schema.'.services')->insertGetId([
            'name' => $service['name'],
            'description' => $service['description'],
            'duration_min' => $service['duration_min'],
            'processing_gap_min' => $service['processing_gap_min'] ?? 0,
            'price_minor' => $service['price_minor'],
            'currency' => $currency,
            'payment_mode' => $service['payment_mode'],
            'requires_approval' => $service['requires_approval'] ?? false,
            'status' => $service['status'],
            'sort' => $sort,
        ]);
    }

    private function implicitStaffId(string $schema): int
    {
        $id = DB::table($schema.'.staff')->where('is_implicit', true)->value('id');

        if (! is_int($id)) {
            throw new LogicException(sprintf('Schema "%s" has no implicit staff member; provisioning inserts one.', $schema));
        }

        return $id;
    }

    private function currencyOf(string $slug): string
    {
        $currency = DB::table('tenants')->where('slug', $slug)->value('currency');

        if (! is_string($currency)) {
            throw new LogicException(sprintf('Tenant "%s" is not provisioned; DemoTenantsSeeder runs first.', $slug));
        }

        return $currency;
    }
}
