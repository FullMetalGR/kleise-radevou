<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The business page's two pictures: the cover's path, null for none, and the gallery's paths in
 * the order they were added, each a path the tenant image store answers, served at GET
 * /images/{file}. The CHECK holds the gallery at eight photos or fewer, the cap the upload
 * refuses the ninth under, so no write that misses the upload's own count can leave a ninth.
 *
 * Additive and safe on a schema holding rows: a nullable column and an array defaulting to the
 * empty one are catalog changes, and every existing tenant reads no cover and an empty gallery,
 * which the page renders as nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                add column cover_path text,
                add column gallery_paths text[] not null default '{}',
                add constraint settings_gallery_paths_check check (cardinality(gallery_paths) <= 8)
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                drop constraint settings_gallery_paths_check,
                drop column gallery_paths,
                drop column cover_path
            SQL);
    }
};
