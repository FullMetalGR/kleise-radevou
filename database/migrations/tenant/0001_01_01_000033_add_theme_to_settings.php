<?php

use App\Tenancy\ExclusiveStep;
use App\Tenancy\Theme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The tenant's named theme: settings.theme, one lowercase word, neutral by default, held to the
 * seven of App\Tenancy\Theme by its CHECK, settings_theme_check. The CHECK restates
 * Theme::words() in SQL, since a statement here is a literal string by the static analysis's
 * rule, and TenantSchemaTest holds the two to the same words in the same order. In the same step
 * each tenant's saved accent, settings.brand_accent, is mapped to the nearest theme by
 * Theme::nearest(), the one rule for it: no accent and a grey read neutral. The accent column
 * stays as it is; a later migration removes it.
 *
 * Safe on a schema holding live bookings. Adding a column with a constant default rewrites no
 * table, the CHECK scans the one settings row and the update writes that row alone. The ALTER
 * takes ACCESS EXCLUSIVE on settings alone, held to the schema's commit; before the lock is
 * granted it waits behind every open transaction that has read settings, the booking path among
 * them, so it runs under ExclusiveStep, and gives up after the transaction runner's lock timeout,
 * the schema rolled back and named by tenant:migrate to be run again. Nothing of bookings or
 * booking_items is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared(<<<'SQL'
                alter table settings
                    add column theme text not null default 'neutral' constraint settings_theme_check check (theme in ('neutral', 'blue', 'green', 'orange', 'red', 'rose', 'violet'))
                SQL);

            $accent = DB::table('settings')->where('id', 1)->value('brand_accent');
            $theme = Theme::nearest(is_string($accent) ? $accent : null);

            if ($theme !== Theme::DEFAULT) {
                DB::table('settings')->where('id', 1)->update(['theme' => $theme->value]);
            }
        });
    }

    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('alter table settings drop column theme');
        });
    }
};
