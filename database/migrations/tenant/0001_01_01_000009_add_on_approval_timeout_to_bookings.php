<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The approval timeout policy copied onto the booking at hold time: expire, or auto_approve,
 * resolved across the chain so that auto_approve holds only when every segment requiring
 * approval says so. The blocking predicate reads it: an approval request whose policy is
 * auto_approve keeps blocking past its expiry, because the clock has already decided it in the
 * owner's favour and the sweeper's confirmation is bookkeeping.
 *
 * Additive and safe on a schema holding rows: a not-null column with a constant default is a
 * catalog change, every existing row reads expire, and the CHECK admits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table bookings
                add column on_approval_timeout text not null default 'expire',
                add constraint bookings_on_approval_timeout_check
                    check (on_approval_timeout in ('expire', 'auto_approve'))
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('alter table bookings drop column on_approval_timeout');
    }
};
