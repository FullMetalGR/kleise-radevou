<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The trail every transition writes. A row records what changed and references the booking; it
 * is never updated and never rewritten, which is why it carries a created_at and no updated_at.
 *
 * No personal data in payload, ever. The audit log is the one table with no erasure step, and
 * it earns that by holding nothing to erase. The actor CHECK is the structural half of the
 * promise: the four shapes are the whole vocabulary, so a name or an email cannot be stored as
 * an actor whatever a later caller passes. The payload's half is the declared key list in
 * App\Domain\Audit\AuditKey, held by a test over every row the suites write, because a jsonb
 * column cannot constrain its own keys.
 *
 * The two patterns are the ones App\Domain\Audit\Actor builds from, spelled here as POSIX
 * regexes rather than imported, because a shipped migration must not change when a constant
 * does. A test holds the constraint and the class to each other instead, by asking both what
 * they accept rather than by comparing two texts: the same pattern read as PCRE and as POSIX
 * is not the same pattern, and the dollar is where they differ.
 *
 * booking_id carries no foreign key. Retention deletes the trail outright at 24 months while
 * the booking is kept past that with its customer fields nulled rather than removed, so the
 * booking outlives its trail and there is no deletion for a cascade to follow. What the
 * constraint would buy is the guarantee that a row names a booking that exists, and the insert
 * already gives that: it resolves the booking from its uuid and the writer refuses a row that
 * matched none. What it would cost is a check on every insert on the path that holds the mutex
 * rows.
 *
 * The index is (booking_id, created_at): the admin's booking dialog reads one booking's trail
 * oldest first, which is exactly this index read forwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table audit_log (
                id bigserial primary key,
                booking_id bigint not null,
                actor text not null,
                action text not null,
                payload jsonb not null default '{}'::jsonb,
                created_at timestamptz not null default now(),
                constraint audit_log_actor_check check (
                    actor in ('customer', 'system')
                    or actor ~ '^user:[0-9]+$'
                    or actor ~ '^job:[a-z][a-z0-9_-]*$'
                )
            )
            SQL);

        DB::unprepared('create index audit_log_booking on audit_log (booking_id, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
