<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The business page's profile: the text the owner writes in the Booking page section of
 * Settings and the hosted page renders above the flow, and Important info, the one text the
 * review step and the confirmation carry. Every column is nullable and an empty field is
 * stored as null; the country alone has a default, GR, the select's own.
 *
 * Additive and safe on a schema holding rows: nullable columns are a catalog change, and every
 * existing tenant reads an empty profile, which the page renders as nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                add column about text,
                add column address_line1 text,
                add column address_line2 text,
                add column city text,
                add column postal_code text,
                add column country text default 'GR',
                add column phone text,
                add column public_email text,
                add column website text,
                add column instagram text,
                add column facebook text,
                add column google_reviews_url text,
                add column important_info text
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                drop column important_info,
                drop column google_reviews_url,
                drop column facebook,
                drop column instagram,
                drop column website,
                drop column public_email,
                drop column phone,
                drop column country,
                drop column postal_code,
                drop column city,
                drop column address_line2,
                drop column address_line1,
                drop column about
            SQL);
    }
};
