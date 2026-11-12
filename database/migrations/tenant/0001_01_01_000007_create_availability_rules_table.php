<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A recurring weekly window for one staff member: a weekday, a start and an end as wall-clock
 * times in the tenant's zone with no instant and no conversion, and an optional validity range
 * of dates with both ends inclusive. Several rows per weekday are normal, a split day around
 * lunch being two; a weekday with no rows is a day that member does not work. The tenant's
 * opening hours are the implicit member's rows here under another name, and nothing in this
 * table can tell them apart.
 *
 * The CHECKs mirror what the domain refuses, so a hand-edited row cannot reach it: the weekday
 * is ISO 1 to 7, the start precedes the end, and a validity range does not end before it starts.
 * An end-of-day window is spelled 24:00:00, which the time type accepts and the domain reads as
 * the next midnight; 23:59:59 would lose the last slot to inward rounding. The rows follow their
 * staff member out of the table, the way assignments do: the booking path only reads here, so
 * the foreign key takes no lock it would wait behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table availability_rules (
                id bigserial primary key,
                staff_id bigint not null references staff (id) on delete cascade,
                weekday integer not null,
                start_time time not null,
                end_time time not null,
                valid_from date,
                valid_to date,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint availability_rules_weekday_check check (weekday between 1 and 7),
                constraint availability_rules_window_check check (start_time < end_time),
                constraint availability_rules_validity_check
                    check (valid_from is null or valid_to is null or valid_from <= valid_to)
            )
            SQL);

        DB::unprepared('create index availability_rules_staff_weekday on availability_rules (staff_id, weekday)');
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_rules');
    }
};
