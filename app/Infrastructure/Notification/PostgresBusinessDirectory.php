<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Db\SqlText;
use App\Notifications\BusinessDirectory;
use App\Notifications\StaffOnBooking;
use App\Notifications\TenantUser;
use App\Site\SiteLanguage;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * The two reads the recipient rule runs on, over one tenant's rows.
 *
 * Two statements and not a join, because the rows are in two schemas: users are central and
 * staff rows are the tenant's, related by id and never by a foreign key across schemas.
 * Joining across them would work today and would stop working the day the central tables move,
 * which is the reason the schema never does it.
 *
 * Both are ordered by id, so a message's recipients come out in one order whatever the planner
 * felt like, and a test can name them.
 *
 * The tenant's tables are named by schema and nothing is set on the connection, the way every
 * other reader behind a public route honours the pooling rule.
 */
final readonly class PostgresBusinessDirectory implements BusinessDirectory
{
    public function __construct(private DatabaseManager $db, private Tenant $tenant) {}

    public function users(): array
    {
        $users = [];

        foreach ($this->db->connection()->table('users')->where('tenant_id', $this->tenant->id)->orderBy('id')->get(['id', 'name', 'email', 'role', 'locale']) as $row) {
            // The role is read through tryFrom and guarded on the answer, not guarded as a
            // string and then read. A check constraint keeps the column to three words, and
            // the enum is this build's idea of which three: a role added by a later migration,
            // or a row a hand fixed, is a value the guard must refuse rather than one that
            // reaches Role::from and raises a ValueError from behind the guard.
            $role = is_string($row->role) ? Role::tryFrom($row->role) : null;
            // The language the user chose, null for none, read the same way: a CHECK keeps the
            // column to en and el, and anything else is a row the guard refuses.
            $chosen = is_string($row->locale) ? SiteLanguage::tryFrom($row->locale) : null;

            if (! is_int($row->id) || ! is_string($row->name) || ! is_string($row->email) || $role === null
                || ($row->locale !== null && $chosen === null)) {
                throw new LogicException(sprintf('A users row of tenant "%s" is not shaped as the schema declares.', $this->tenant->slug));
            }

            $users[] = new TenantUser($row->id, $row->name, $row->email, $role, $chosen);
        }

        return $users;
    }

    public function staffOn(string $uuid): array
    {
        // A string that is no uuid names no booking, and it is answered here rather than by
        // the server: the column is a uuid, so a malformed literal raises 22P02 on the cast,
        // which is an error thrown at a caller that asked a question with an ordinary answer.
        // The answer is nobody, the same as for a uuid no booking carries.
        if (! SqlText::isUuid($uuid)) {
            return [];
        }

        $rows = $this->db->connection()
            ->table($this->tenant->schema_name.'.staff', 'member')
            ->join($this->tenant->schema_name.'.booking_items as item', 'item.staff_id', '=', 'member.id')
            ->join($this->tenant->schema_name.'.bookings as booking', 'booking.id', '=', 'item.booking_id')
            ->where('booking.uuid', $uuid)
            ->distinct()
            ->orderBy('member.id')
            ->get(['member.id', 'member.email', 'member.user_id', 'member.is_implicit']);

        $staff = [];

        foreach ($rows as $row) {
            if (! is_int($row->id) || ! is_bool($row->is_implicit)
                || ($row->email !== null && ! is_string($row->email))
                || ($row->user_id !== null && ! is_int($row->user_id))) {
                throw new LogicException(sprintf('A staff row of tenant "%s" is not shaped as the schema declares.', $this->tenant->slug));
            }

            $staff[] = new StaffOnBooking($row->id, $row->email, $row->user_id, $row->is_implicit);
        }

        return $staff;
    }
}
