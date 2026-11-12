<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A user belongs to exactly one tenant. Email is unique per tenant rather than globally, so the
 * same person may hold a login at two tenants. The password reset and session tables are the
 * framework's own and keep its shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table users (
                id bigserial primary key,
                tenant_id bigint not null references tenants (id),
                name text not null,
                email citext not null,
                email_verified_at timestamptz,
                password text not null,
                role text not null,
                remember_token varchar(100),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint users_tenant_id_email_unique unique (tenant_id, email),
                constraint users_role_check check (role in ('owner', 'manager', 'staff'))
            )
            SQL);

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
