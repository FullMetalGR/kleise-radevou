<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Clock;
use App\Application\Dto\CustomerNote;
use App\Domain\Audit\Actor;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * The staff's notes on customers, the customer_notes table: read for one address newest first,
 * as the page's Notes card lists them, written one at a time with the author's actor and name
 * and the Clock's instant, and removed one at a time where the note is the address's own. The
 * address is matched as citext compares it and stored in lower case, as the profiles' is. Every
 * table is named by the tenant's schema, as the customers' reader names it.
 */
final readonly class CustomerNotes
{
    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    /**
     * The notes of an address, newest first, the later id first within one instant.
     *
     * @return list<CustomerNote>
     */
    public function of(Tenant $tenant, string $email): array
    {
        $shape = RowShape::of('customer notes', $tenant);
        $rows = $this->db->connection()->select(
            sprintf(
                'select id, body, author_name, %s as created_at from %s.customer_notes where email = ?::citext order by created_at desc, id desc',
                SqlText::instantColumn('created_at'),
                $tenant->schema_name,
            ),
            [$email],
        );

        return array_values(array_map(function (mixed $row) use ($shape): CustomerNote {
            $row = $shape->row($row);

            return new CustomerNote($shape->int($row->id), $shape->string($row->body), $shape->string($row->author_name), SqlText::readInstant($shape->string($row->created_at)));
        }, $rows));
    }

    /**
     * Writes a note on an address under its author's actor and name.
     */
    public function add(Tenant $tenant, string $email, string $body, Actor $author, string $name): void
    {
        $this->db->connection()->insert(
            sprintf('insert into %s.customer_notes (email, body, author_actor, author_name, created_at) values (lower(?), ?, ?, ?, ?::timestamptz)', $tenant->schema_name),
            [$email, $body, (string) $author, $name, SqlText::instant($this->clock->now())],
        );
    }

    /**
     * Removes the note of the id given where it is the address's own; answers whether one was.
     */
    public function remove(Tenant $tenant, string $email, int $id): bool
    {
        return $this->db->connection()->delete(
            sprintf('delete from %s.customer_notes where id = ? and email = ?::citext', $tenant->schema_name),
            [$id, $email],
        ) > 0;
    }
}
