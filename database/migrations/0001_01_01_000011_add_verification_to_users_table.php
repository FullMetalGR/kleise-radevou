<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The verified signup: the owner's link is 32 random bytes as hex, and the users row keeps its
 * sha256 beside email_verified_at, which the click stamps to mark the link used, the hash kept
 * after it, and the clear token lives in the link alone. One hash names one owner, so the column
 * is unique, nulls apart. Beside it the trial's end made a rule of the table: a row in state
 * trial carries the instant it ends, or the hourly lapse would never reach it. The implication
 * runs one way, since the lapse keeps the end on the canceled row it writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table users
            add column verification_token_hash text,
            add constraint users_verification_token_hash_unique unique (verification_token_hash)');

        DB::statement("alter table tenants
            add constraint tenants_trial_ends_check check (subscription_state <> 'trial' or trial_ends_at is not null)");
    }

    public function down(): void
    {
        DB::statement('alter table tenants drop constraint tenants_trial_ends_check');
        DB::statement('alter table users drop constraint users_verification_token_hash_unique, drop column verification_token_hash');
    }
};
