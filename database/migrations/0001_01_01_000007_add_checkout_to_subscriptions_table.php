<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The checkout a signup opened, remembered on the subscriptions row beside the customer: the
 * provider's session identifier, the URL that sends the person to it, and the instant it opened.
 * A holder coming back to the signup form is sent to that same session rather than a second one
 * beside it, which could land two subscriptions on one customer. The completion clears the three
 * once the sync's write for the pair has landed, and the row takes them with it when the sweep
 * gives the slug back. The three are written and cleared together, and the constraint says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table subscriptions
            add column checkout_session_id text,
            add column checkout_url text,
            add column checkout_opened_at timestamptz,
            add constraint subscriptions_checkout_whole check (
                (checkout_session_id is null) = (checkout_url is null)
                and (checkout_url is null) = (checkout_opened_at is null)
            )');
    }

    public function down(): void
    {
        DB::statement('alter table subscriptions
            drop constraint subscriptions_checkout_whole,
            drop column checkout_session_id,
            drop column checkout_url,
            drop column checkout_opened_at');
    }
};
