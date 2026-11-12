<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The contact form's messages: a row per message the form's POST let through, inserted in one
 * statement with the page's language, the person's name, address and message as given, and the
 * clock's now as UTC text with its offset. archived_at is the console's Archive, the one write
 * after the insert. No address of the sender's machine is stored, and no updated_at: the row is
 * written once and archived once.
 *
 * The language is held to the site's two by a CHECK, as every enumerated column is. The row is a
 * prospect's personal data, kept 12 months from created_at, archived or not, then deleted by the
 * retention sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table contact_messages (
                id bigserial primary key,
                name text not null,
                email text not null,
                message text not null,
                locale text not null,
                created_at timestamptz not null,
                archived_at timestamptz,
                constraint contact_messages_locale_check check (locale in ('en', 'el'))
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
