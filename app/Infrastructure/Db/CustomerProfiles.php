<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Clock;
use App\Application\Dto\CustomerProfile;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * The customers' profiles, the customer_profiles table: one row per address, keyed by the address
 * in lower case and compared as citext compares it, so the page and the list read it as they read
 * the bookings of the address. The list reads the rows of a page's addresses in one statement, the
 * page reads its own, and the Profile card's save writes the three fields it holds in one upsert,
 * leaving the block, which the card does not hold, as it stands. The Online booking card's block
 * writes the instant from the Clock and the reason in one upsert that leaves the three fields as
 * they stand, and its unblock clears both on the row there is; the hold asks whether an address is
 * blocked. Every table is named by the tenant's schema, as the customers' reader names it.
 */
final readonly class CustomerProfiles
{
    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    /**
     * The profiles of the addresses given, each under the address as it was given, matched as
     * citext compares it; an address with no row is absent.
     *
     * @param  list<string>  $emails
     * @return array<string, CustomerProfile>
     */
    public function of(Tenant $tenant, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $shape = RowShape::of('customer profiles', $tenant);
        $profiles = [];
        $rows = $this->db->connection()->select(
            sprintf(
                'select asked.email, profile.tags::text as tags, profile.birthday::text as birthday, profile.language, profile.blocked_at is not null as blocked,'
                .' '.SqlText::instantColumn('profile.blocked_at').' as blocked_at, profile.blocked_reason'
                .' from unnest(?::text[]) as asked(email) join %s.customer_profiles profile on profile.email = asked.email::citext',
                $tenant->schema_name,
            ),
            [TextArray::encode($emails)],
        );

        foreach ($rows as $row) {
            $row = $shape->row($row);
            $profiles[$shape->string($row->email)] = new CustomerProfile(
                TextArray::decode($shape->string($row->tags)),
                $row->birthday === null ? null : $shape->string($row->birthday),
                $row->language === null ? null : $shape->string($row->language),
                $shape->bool($row->blocked),
                $row->blocked_at === null ? null : SqlText::readInstant($shape->string($row->blocked_at)),
                $row->blocked_reason === null ? null : $shape->string($row->blocked_reason),
            );
        }

        return $profiles;
    }

    /**
     * The profile of one address, none where no row is.
     */
    public function one(Tenant $tenant, string $email): CustomerProfile
    {
        return $this->of($tenant, [$email])[$email] ?? CustomerProfile::none();
    }

    /**
     * Writes the Profile card's three fields for an address, the row made where none is, the
     * address stored in lower case and the block left as it stands.
     *
     * @param  list<string>  $tags
     */
    public function save(Tenant $tenant, string $email, array $tags, ?string $birthday, ?string $language): void
    {
        $now = SqlText::instant($this->clock->now());

        $this->db->connection()->insert(
            sprintf(
                'insert into %s.customer_profiles (email, tags, birthday, language, created_at, updated_at)'
                .' values (lower(?), ?::text[], ?::date, ?, ?::timestamptz, ?::timestamptz)'
                .' on conflict (email) do update set tags = excluded.tags, birthday = excluded.birthday, language = excluded.language, updated_at = excluded.updated_at',
                $tenant->schema_name,
            ),
            [$email, TextArray::encode($tags), $birthday, $language, $now, $now],
        );
    }

    /**
     * Blocks online booking for an address with the reason given, null for none, stamped with
     * the Clock's instant; the row made where none is, the profile's fields left as they stand.
     */
    public function block(Tenant $tenant, string $email, ?string $reason): void
    {
        $now = SqlText::instant($this->clock->now());

        $this->db->connection()->insert(
            sprintf(
                'insert into %s.customer_profiles (email, blocked_at, blocked_reason, created_at, updated_at)'
                .' values (lower(?), ?::timestamptz, ?, ?::timestamptz, ?::timestamptz)'
                .' on conflict (email) do update set blocked_at = excluded.blocked_at, blocked_reason = excluded.blocked_reason, updated_at = excluded.updated_at',
                $tenant->schema_name,
            ),
            [$email, $now, $reason, $now, $now],
        );
    }

    /**
     * Lifts the block on an address, clearing its instant and its reason; an address with no
     * row keeps having none.
     */
    public function unblock(Tenant $tenant, string $email): void
    {
        $this->db->connection()->update(
            sprintf('update %s.customer_profiles set blocked_at = null, blocked_reason = null, updated_at = ?::timestamptz where email = ?::citext and blocked_at is not null', $tenant->schema_name),
            [SqlText::instant($this->clock->now()), $email],
        );
    }

    /**
     * Whether online booking is blocked for an address, matched as citext compares it: the
     * hold's question.
     */
    public function blocked(Tenant $tenant, string $email): bool
    {
        return $this->db->connection()->table($tenant->schema_name.'.customer_profiles')
            ->whereRaw('email = ?::citext', [$email])
            ->whereNotNull('blocked_at')
            ->exists();
    }
}
