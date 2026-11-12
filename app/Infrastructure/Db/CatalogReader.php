<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\CatalogService;
use App\Application\Dto\CatalogStaff;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Reads a tenant's public catalog for a request that opens no transaction: the active services
 * offered online, in sort order, each with its category and the active, online, named staff
 * members assigned to it. A service or a member the owner has marked not offered online is off
 * this read exactly as an inactive one is: the admin's wizard and the calendar read the whole
 * catalogue through WizardCatalog, and the engine's pool on the public road is these same
 * members, PostgresServiceRepository on Pool::Online.
 *
 * The implicit staff member is never in a list, so a floor tenant's customers never meet a
 * picker offering the business as a person. An inactive assignment is left out the same way, so
 * a staff member taken off the roster stops being offered without their assignments being
 * touched.
 *
 * Every table is named by schema in the query and nothing is set on the connection, the way the
 * settings reader honours the pooling rule: a schema-qualified name needs no search path, so a
 * pooled connection carries nothing from this request into the next.
 */
final readonly class CatalogReader
{
    public function __construct(
        private DatabaseManager $db,
        private TenantUrls $urls,
    ) {}

    /**
     * @return list<CatalogService>
     */
    public function activeServices(Tenant $tenant): array
    {
        return $this->read($tenant, $this->services($tenant));
    }

    /**
     * One active service offered online, or null for an inactive or offline one exactly as for
     * an unknown id.
     */
    public function activeService(Tenant $tenant, int $id): ?CatalogService
    {
        return $this->read($tenant, $this->services($tenant)->where('id', $id))[0] ?? null;
    }

    private function services(Tenant $tenant): Builder
    {
        return $this->db->connection()
            ->table($tenant->schema_name.'.services')
            ->where('status', 'active')
            ->where('online', true)
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * @return list<CatalogService>
     */
    private function read(Tenant $tenant, Builder $services): array
    {
        $rows = $services->get(['id', 'name', 'description', 'duration_min', 'price_minor', 'currency', 'category', 'online']);
        $staffByService = $this->staffByService($tenant, $rows->pluck('id')->all());
        $catalog = [];

        foreach ($rows as $row) {
            if (! is_int($row->id) || ! is_string($row->name) || ! is_string($row->description)
                || ! is_int($row->duration_min) || ! is_int($row->price_minor) || ! is_string($row->currency)
                || ($row->category !== null && ! is_string($row->category)) || ! is_bool($row->online)) {
                throw new LogicException(sprintf('A services row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
            }

            $catalog[] = new CatalogService(
                $row->id,
                $row->name,
                $row->description,
                $row->duration_min,
                new Money($row->price_minor, Currency::of($row->currency)),
                $staffByService[$row->id] ?? [],
                $row->category,
                $row->online,
            );
        }

        return $catalog;
    }

    /**
     * The active, online, named staff members assigned to each of the services, keyed by
     * service id, each list in the staff sort order, each with the URL its photo is served at,
     * GET /images/{file} on the tenant's origin, its title and its bio.
     *
     * @param  array<mixed>  $serviceIds
     * @return array<int, list<CatalogStaff>>
     */
    private function staffByService(Tenant $tenant, array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table($tenant->schema_name.'.service_staff', 'assignment')
            ->join($tenant->schema_name.'.staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->whereIn('assignment.service_id', $serviceIds)
            ->where('staff.status', 'active')
            ->where('staff.online', true)
            ->where('staff.is_implicit', false)
            ->orderBy('staff.sort')
            ->orderBy('staff.id')
            ->get(['assignment.service_id', 'staff.id', 'staff.name', 'staff.photo_path', 'staff.title', 'staff.bio']);

        $byService = [];
        $slug = Slug::of($tenant->slug);

        foreach ($rows as $row) {
            if (! is_int($row->service_id) || ! is_int($row->id) || ! is_string($row->name)) {
                throw new LogicException(sprintf('A staff row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
            }

            $photo = is_string($row->photo_path) ? $this->urls->image($slug, TenantImages::fileOf($row->photo_path)) : null;
            $byService[$row->service_id][] = new CatalogStaff($row->id, $row->name, $photo, is_string($row->title) ? $row->title : null, is_string($row->bio) ? $row->bio : null);
        }

        return $byService;
    }
}
