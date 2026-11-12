<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * How often a customer is offered a start: the two customer availability reads answer only the
 * starts whose minute of the hour on the tenant's clock is a multiple of it, where the engine's
 * grid stays granularity_min. It shapes the offer and nothing else; a hold at a free start off it
 * is still accepted.
 *
 * The CHECK holds the two rules the offer depends on. Dividing the hour is what makes the same
 * starts fall in every hour of every day, a clock-change day included; a multiple of the
 * granularity is what keeps every offered start on the grid the engine books against, so a
 * change to either column that breaks the pair is refused, whichever column it names. The
 * leading "> 0" is not redundant: PostgreSQL computes 60 % -15 as 0, so without it a negative
 * divisor of the hour would pass both rules.
 *
 * Additive and safe on a schema holding rows: a not-null column with a constant default is a
 * catalog change, the single row reads 15, and the CHECK admits it over the default granularity
 * of 5. Every existing tenant starts offering quarter-hour starts from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                add column start_interval_min integer not null default 15,
                add constraint settings_start_interval_check
                    check (start_interval_min > 0 and 60 % start_interval_min = 0 and start_interval_min % granularity_min = 0)
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                drop constraint settings_start_interval_check,
                drop column start_interval_min
            SQL);
    }
};
