<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The trial's two notices: the platform tells a trial tenant's owners when three days are left
 * and on the day it ends, each once. Each notice has its own stamp, the instant the hourly
 * trial:notify claimed it, written in the statement that decides the send, so a run that crashed
 * after the write sends no second copy and two runs at once send one. Null until the notice is
 * sent; nothing clears either.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table tenants
            add column trial_three_days_sent_at timestamptz,
            add column trial_ends_today_sent_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('alter table tenants
            drop column trial_ends_today_sent_at,
            drop column trial_three_days_sent_at');
    }
};
