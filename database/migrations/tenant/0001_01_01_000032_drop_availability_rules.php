<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The contract step: availability_rules goes, its three CHECKs and its staff and weekday index
 * with it. Since migration 000030 a member's week has been their shift_patterns row, and from
 * this migration on nothing reads or writes the weekday rules: the resolver, the screens, the
 * export, the provisioner and the seeds read and write the patterns alone.
 *
 * Safe on a schema holding live bookings. The booking path never wrote here and no longer reads
 * here. The drop takes ACCESS EXCLUSIVE on availability_rules, and on staff too: the table's key
 * to staff goes with it and takes its triggers off staff, so the drop waits behind any open
 * transaction that read either table, a hold or a reader of staff among them. It runs under
 * ExclusiveStep, and gives up after the transaction runner's lock timeout, the schema rolled
 * back and named by tenant:migrate to be run again (TenantMigrationLockTest proves both: an open
 * reader of availability_rules, and one of staff, each stops it at the timeout and it lands once
 * the reader ends). Nothing of bookings or booking_items is touched.
 *
 * down() puts the table back as migration 000007 made it and writes each pattern's windows into
 * it as weekday rules with no validity dates, the spelling the screens read before 000030; the
 * cadence, the dates and the overrides have no such spelling and are left to the patterns.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('drop table availability_rules');
        });
    }

    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
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
            DB::unprepared(<<<'SQL'
                insert into availability_rules (staff_id, weekday, start_time, end_time)
                select pattern.staff_id, (w.value ->> 'weekday')::integer, (w.value ->> 'start')::time, (w.value ->> 'end')::time
                from shift_patterns pattern cross join lateral jsonb_array_elements(pattern.windows) with ordinality w (value, ordinality)
                order by pattern.staff_id, w.ordinality
                SQL);
        });
    }
};
