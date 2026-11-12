<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which staff members perform which services: one row per pair, the pair unique. Replaced as a
 * whole on every save, from either side, so the rows follow their service and their staff
 * member out of the table when either is deleted rather than lingering as an assignment to
 * nothing. The index on staff_id serves the staff side of that replace; the unique index
 * already serves the service side.
 *
 * The foreign keys are safe where the one on staff_days was not: this table is written by an
 * admin save and only read by the booking path, and a read takes no lock on the referenced row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table service_staff (
                id bigserial primary key,
                service_id bigint not null references services (id) on delete cascade,
                staff_id bigint not null references staff (id) on delete cascade,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint service_staff_pair_unique unique (service_id, staff_id)
            )
            SQL);

        DB::unprepared('create index service_staff_staff on service_staff (staff_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_staff');
    }
};
