<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The free accent colour removed: settings.brand_accent dropped, the named theme of 000033 the
 * one colour a tenant chooses. 000033 mapped every saved accent to its nearest theme, so nothing
 * a tenant chose is lost here; nothing reads the column since the Settings screen, the hosted
 * page and the embed moved to the theme.
 *
 * The way down puts the column back nullable and empty, the product default it meant before any
 * accent was saved; the accents themselves are not restored.
 *
 * Safe on a schema holding live bookings. Dropping a column rewrites no table, it marks the
 * column dropped in the catalog. The ALTER takes ACCESS EXCLUSIVE on settings alone, held to the
 * schema's commit; before the lock is granted it waits behind every open transaction that has
 * read settings, the booking path among them, so it runs under ExclusiveStep, and gives up after
 * the transaction runner's lock timeout, the schema rolled back and named by tenant:migrate to
 * be run again. Nothing of bookings or booking_items is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('alter table settings drop column brand_accent');
        });
    }

    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('alter table settings add column brand_accent text');
        });
    }
};
