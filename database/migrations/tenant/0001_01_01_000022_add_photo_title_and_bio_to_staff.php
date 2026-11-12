<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A face and a line for each person a customer chooses between: staff.photo_path, the path the
 * tenant image store wrote for the member's photo, as settings.brand_logo_path holds the logo's;
 * staff.title, a short line such as "Senior stylist", at most 60 characters; and staff.bio, at
 * most 500. Each is null for a member without one.
 *
 * The CHECKs hold the title and the bio to the lengths the admin write refuses past, so a
 * hand-edited row cannot carry text the form would not save, and to a non-empty value, since the
 * write lands an empty one as null.
 *
 * Additive and safe on a schema holding rows: three nullable columns are catalog changes, and
 * every existing member reads as one with no photo, no title and no bio.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table staff
                add column photo_path text,
                add column title text,
                add column bio text,
                add constraint staff_title_check check (title is null or char_length(title) between 1 and 60),
                add constraint staff_bio_check check (bio is null or char_length(bio) between 1 and 500)
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table staff
                drop constraint staff_bio_check,
                drop constraint staff_title_check,
                drop column bio,
                drop column title,
                drop column photo_path
            SQL);
    }
};
