<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * One level of grouping for the catalogue and the switch that keeps a service or a person off
 * the public page without deactivating them: services.category, a heading the booking page
 * groups rows under, at most 60 characters and null for a row under none; services.online and
 * staff.online, true where the customer may book the row on the public road. The admin's wizard
 * and the calendar read every row whatever its flag.
 *
 * The CHECK holds the category to the length the admin write refuses past, so a hand-edited row
 * cannot carry a heading the form would not save, and to a non-empty value, since the write
 * lands an empty one as null and a blank heading would read as a group with no name.
 *
 * Additive and safe on a schema holding rows: a nullable column and two booleans defaulting to
 * true are catalog changes, and every existing row stays on the public page as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table services
                add column category text,
                add column online boolean not null default true,
                add constraint services_category_check check (category is null or char_length(category) between 1 and 60);
            alter table staff
                add column online boolean not null default true
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table staff
                drop column online;
            alter table services
                drop constraint services_category_check,
                drop column online,
                drop column category
            SQL);
    }
};
