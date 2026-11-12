<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The business page's map: the point the address was geocoded to, the stored map image's path,
 * and the map's state, which the settings save moves to pending when it changes the address and
 * the queued job settles to ready or failed; none where there is no map to make, the key being
 * unset or the address empty. The CHECK holds the state to its four words, so no write can leave
 * one the screen has no line for.
 *
 * Additive and safe on a schema holding rows: nullable columns and a text defaulting to none are
 * catalog changes, and every existing tenant reads no map until a save changes its address.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                add column lat double precision,
                add column lng double precision,
                add column map_path text,
                add column map_state text not null default 'none',
                add constraint settings_map_state_check check (map_state in ('none', 'pending', 'ready', 'failed'))
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                drop constraint settings_map_state_check,
                drop column map_state,
                drop column map_path,
                drop column lng,
                drop column lat
            SQL);
    }
};
