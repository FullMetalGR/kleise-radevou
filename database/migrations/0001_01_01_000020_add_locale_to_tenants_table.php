<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The language a signup was made in: en or el, the site's two, held by a CHECK; en for every row
 * that exists, the console's rows included, since every signup so far was English. The
 * reservation writes it from the route's language, the platform's tenant-facing mails read it,
 * and the tenant default language starts from it. Not personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table tenants
            add column locale text not null default 'en',
            add constraint tenants_locale_check check (locale in ('en', 'el'))");
    }

    public function down(): void
    {
        DB::statement('alter table tenants
            drop constraint tenants_locale_check,
            drop column locale');
    }
};
