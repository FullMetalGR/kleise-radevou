<?php

declare(strict_types=1);

namespace App\Platform;

use App\Site\SiteLanguage;
use App\Tenancy\Role;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use DateTimeZone;
use Illuminate\Database\Connection;
use stdClass;

/**
 * A tenant as its owners are told about it after a write to its row, a console write or the
 * owner's own close: who the business is, the clock it keeps, and the owner users to tell, each
 * by address, name and the language they chose, users.locale, read in the transaction that wrote
 * the change. The tenant's model rides beside them for the language each owner is told in, their
 * own else the tenant default, which the mail asks the resolver at send time, after the commit.
 */
final readonly class AccountOwners
{
    /**
     * @param  list<array{address: string, name: string, locale: SiteLanguage|null}>  $owners
     */
    public function __construct(
        public int $id,
        public Slug $slug,
        public string $name,
        public DateTimeZone $zone,
        public array $owners,
        public Tenant $tenant,
    ) {}

    /**
     * The tenant as its owners are told about it, from its row as the writer read it (slug, name
     * and timezone), its owner users read on the writer's connection in the order they
     * were made, each with the language they chose. $reader names the writer in the sentence a
     * misshapen row raises.
     */
    public static function read(Connection $db, Tenant $tenant, stdClass $row, string $reader): self
    {
        $owners = [];

        foreach ($db->select('select email, name, locale from users where tenant_id = ? and role = ? order by id', [$tenant->id, Role::Owner->value]) as $owner) {
            if (! $owner instanceof stdClass) {
                throw CentralRow::misshapen('users', $reader);
            }

            $locale = CentralRow::optionalText($owner, 'locale', $reader);
            $owners[] = [
                'address' => CentralRow::text($owner, 'email', $reader),
                'name' => CentralRow::text($owner, 'name', $reader),
                'locale' => $locale === null ? null : SiteLanguage::from($locale),
            ];
        }

        return new self(
            $tenant->id,
            Slug::of(CentralRow::text($row, 'slug', $reader)),
            CentralRow::text($row, 'name', $reader),
            new DateTimeZone(CentralRow::text($row, 'timezone', $reader)),
            $owners,
            $tenant,
        );
    }
}
