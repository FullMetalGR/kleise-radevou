<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The owner's acceptance of the terms at signup: the instant the reservation recorded it and the
 * version accepted, the day the terms file's version line names, held set together or null
 * together by a CHECK. The signup form's reservation alone writes them; the console's free
 * tenant form, tenant:provision and demo:reset leave both null, and so does every row before
 * this migration, whose owner the form never asked. Not personal data; the drop keeps the row
 * and both columns with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table tenants
            add column terms_accepted_at timestamptz null,
            add column terms_version date null,
            add constraint tenants_terms_check check ((terms_accepted_at is null) = (terms_version is null))');
    }

    public function down(): void
    {
        DB::statement('alter table tenants
            drop constraint tenants_terms_check,
            drop column terms_version,
            drop column terms_accepted_at');
    }
};
