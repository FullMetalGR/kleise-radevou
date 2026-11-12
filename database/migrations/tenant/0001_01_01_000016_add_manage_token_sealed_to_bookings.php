<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The manage token as the pay hop saw it, encrypted. The confirmation that follows a payment
 * carries the customer's magic link, and the request that confirms it is the provider's
 * notification, which has no token; the hop is the last request that had one. So the hop seals
 * it onto the row in the statement that records payment_ref, and the transition that ends the
 * hold clears it, whichever road ends it: the payment's confirmation carries it on to the
 * queue's encrypted payload, and a lapse, a release or a cancel drops it. Only the ciphertext is
 * ever stored; the hash the guard checks is manage_token_hash, untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('alter table bookings add column manage_token_sealed text');
    }

    public function down(): void
    {
        DB::unprepared('alter table bookings drop column manage_token_sealed');
    }
};
