<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Http\LinkKind;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * The rows a booking link can preselect: every service and every member but the implicit one,
 * who stands for the business and is never a choice; and of those the ones the Links card
 * lists, what the public catalogue offers, a service online and active and a member online,
 * active and named, in the catalogue's and the roster's order.
 *
 * Every table is named by schema and nothing is set on the connection.
 */
final readonly class LinkTargets
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * Every row of a kind a link may name, by id and name, in order.
     *
     * @return list<array{id: int, name: string}>
     */
    public function all(Tenant $tenant, LinkKind $kind): array
    {
        return self::rows($this->of($tenant, $kind));
    }

    /**
     * The rows of a kind the public catalogue offers, by id and name, in order.
     *
     * @return list<array{id: int, name: string}>
     */
    public function offered(Tenant $tenant, LinkKind $kind): array
    {
        return self::rows($this->of($tenant, $kind)->where('status', 'active')->where('online', true));
    }

    public function exists(Tenant $tenant, LinkKind $kind, int $id): bool
    {
        return $this->of($tenant, $kind)->where('id', $id)->exists();
    }

    private function of(Tenant $tenant, LinkKind $kind): Builder
    {
        return match ($kind) {
            LinkKind::Service => $this->db->connection()->table($tenant->schema_name.'.services'),
            LinkKind::Staff => $this->db->connection()->table($tenant->schema_name.'.staff')->where('is_implicit', false),
        };
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function rows(Builder $query): array
    {
        return array_values(array_map(
            fn (stdClass $row): array => ['id' => BlackoutRecords::int($row->id), 'name' => BlackoutRecords::string($row->name)],
            $query->orderBy('sort')->orderBy('id')->get(['id', 'name'])->all(),
        ));
    }
}
