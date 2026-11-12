<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A tenant's connections to the providers that take its customers' money: one row per
 * configured provider and mode. The table holds many rows and v1 writes one, because a tenant
 * has payment connections, plural, and a choice between two at checkout is then a screen
 * rather than a migration.
 *
 * The two unique indexes are the two rules of the model. A provider is connected in a mode at
 * most once, and exactly one row may be the primary: the partial index is over a constant, so
 * a second primary row is refused whatever it names.
 *
 * credentials is text and not jsonb: what is stored is the framework encrypter's output over
 * the JSON object of the fields the provider declared, and a ciphertext is not a document the
 * database could look inside. Null in platform mode. requirements is what the provider last
 * said is outstanding, kept verbatim for display. last_check_ok is the stored result of the
 * last check, null where none has run, so no screen and no booking asks a provider whether a
 * connection works.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table payment_connections (
                id bigserial primary key,
                provider text not null,
                mode text not null,
                state text not null,
                account_ref text,
                credentials text,
                webhook_token text,
                requirements jsonb,
                is_primary boolean not null default false,
                last_checked_at timestamptz,
                last_check_ok boolean,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint payment_connections_mode_check
                    check (mode in ('platform', 'own')),
                constraint payment_connections_state_check
                    check (state in ('pending', 'active', 'restricted', 'disabled'))
            );

            create unique index payment_connections_provider_mode
                on payment_connections (provider, mode);

            create unique index payment_connections_one_primary
                on payment_connections ((true)) where is_primary;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table payment_connections');
    }
};
