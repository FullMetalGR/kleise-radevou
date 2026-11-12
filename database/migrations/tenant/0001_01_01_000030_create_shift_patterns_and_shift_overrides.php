<?php

use App\Infrastructure\Db\WeeklyPattern;
use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The shift patterns and the days edited alone: the expand half of the move from weekday rules to a
 * dated pattern per member.
 *
 * shift_patterns holds one row per member: the first date the pattern holds, its cadence of one
 * to four weeks, its last date or null for never, and its windows as a jsonb list of {weekday,
 * start, end} in the weekday rules' shape, end up to 24:00. shift_overrides holds one row per
 * member and date, its windows a list of {start, end}, an empty list meaning not working that day.
 * The CHECKs hold the cadence to one to four, the end on or after the start, and each list to an
 * array; the shape inside a list is the application's to check, the request classes'. Both follow
 * their member out of the schema through a cascading key, as availability_rules does.
 *
 * Then every member's weekday rules are copied into one pattern: the windows ordered by weekday and
 * start, starts_on the first day of the week holding 2000-01-01 in the week start the settings row
 * names (WeeklyPattern::fromTheStart: Monday 1999-12-27 on a Monday start, Sunday 1999-12-26 on a
 * Sunday one), the one start every weekly pattern has, so the copy holds on every past date the
 * rules held on; cadence one, no end. A member with no rules gets no pattern. valid_from and
 * valid_to are not carried: nothing has ever written them but two tests, every rule of every screen
 * carrying none, so a copy of them would be a copy of nulls. Until migration 000032 drops the
 * rules, every writer writes both spellings. At provisioning the schema has no rules yet when this
 * runs, so the copy writes nothing there and the provisioner writes the default week's pattern
 * itself.
 *
 * Safe on a schema holding live bookings. Creating a table rewrites nothing, and the copy reads
 * availability_rules and settings under ACCESS SHARE, which nothing of the booking path waits on.
 * The two foreign keys take SHARE ROW EXCLUSIVE on staff, held to the schema's commit, which
 * waits for and then holds off a write to staff and nothing the booking path does: a booking
 * item's key share on a staff row takes ROW SHARE, which that lock admits, and reads take ACCESS
 * SHARE. The creates still run under ExclusiveStep, so a long staff write makes the step give up
 * after its lock timeout and tenant:migrate name the schema to run again rather than queue staff
 * writes behind it. Nothing the booking path reads is locked by any of it. down() drops the two
 * tables under ExclusiveStep too, since a drop takes ACCESS EXCLUSIVE on each and would otherwise
 * wait unbounded behind an open availability read and queue every later reader behind itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared(<<<'SQL'
                create table shift_patterns (
                    id bigserial primary key,
                    staff_id bigint not null references staff (id) on delete cascade,
                    starts_on date not null,
                    cadence_weeks integer not null,
                    ends_on date,
                    windows jsonb not null default '[]',
                    created_at timestamptz not null default now(),
                    updated_at timestamptz not null default now(),
                    constraint shift_patterns_staff_unique unique (staff_id),
                    constraint shift_patterns_cadence_check check (cadence_weeks between 1 and 4),
                    constraint shift_patterns_ends_check check (ends_on is null or ends_on >= starts_on),
                    constraint shift_patterns_windows_check check (jsonb_typeof(windows) = 'array')
                )
                SQL);
            DB::unprepared(<<<'SQL'
                create table shift_overrides (
                    id bigserial primary key,
                    staff_id bigint not null references staff (id) on delete cascade,
                    date date not null,
                    windows jsonb not null default '[]',
                    created_at timestamptz not null default now(),
                    updated_at timestamptz not null default now(),
                    constraint shift_overrides_staff_date_unique unique (staff_id, date),
                    constraint shift_overrides_windows_check check (jsonb_typeof(windows) = 'array')
                )
                SQL);
        });

        if (! DB::table('availability_rules')->exists()) {
            return;
        }

        DB::insert(<<<'SQL'
            insert into shift_patterns (staff_id, starts_on, cadence_weeks, windows)
            select staff_id, ?::date, 1, jsonb_agg(
                    jsonb_build_object('weekday', weekday, 'start', to_char(start_time, 'HH24:MI'), 'end', to_char(end_time, 'HH24:MI'))
                    order by weekday, start_time, end_time, id
                )
            from availability_rules
            group by staff_id
            order by staff_id
            SQL, [WeeklyPattern::fromTheStart(DB::connection(), '')]);
    }

    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('drop table shift_overrides');
            DB::unprepared('drop table shift_patterns');
        });
    }
};
