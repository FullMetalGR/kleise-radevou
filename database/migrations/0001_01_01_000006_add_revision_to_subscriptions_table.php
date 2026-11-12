<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The subscriptions row's revision, bumped by every write the subscription sync makes under the
 * tenant row's lock. The billing webhook reads it with the row, decides and asks the provider
 * outside any transaction, and hands it to the write, which refuses when it has moved: two
 * events for one tenant then land as if one had been handled after the other, and no lock is
 * held across the question to the provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table subscriptions add column revision bigint not null default 0');
    }

    public function down(): void
    {
        DB::statement('alter table subscriptions drop column revision');
    }
};
