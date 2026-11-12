<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The user's language: en or el, held by a CHECK, null where the user chose none, which reads as
 * the tenant default. Null on every row that exists, and nothing writes it at a user's creation: a
 * new user starts in the tenant's language and follows it until they choose. The top bar's
 * language control is its one writer, for the signed-in user alone. Not personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table users
            add column locale text,
            add constraint users_locale_check check (locale in ('en', 'el'))");
    }

    public function down(): void
    {
        DB::statement('alter table users
            drop constraint users_locale_check,
            drop column locale');
    }
};
