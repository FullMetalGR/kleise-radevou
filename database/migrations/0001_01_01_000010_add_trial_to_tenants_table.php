<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The trial a verified signup starts on, on the platform's own clock rather than the billing
 * provider's: trial_ends_at on the row, written by provisioning for a trial and cleared by the
 * first subscription's sync write, and the sixth subscription state, trial, which counts as
 * active for the entitlements until the hourly lapse writes canceled at its end. Beside them
 * the business category a signup names, one of seven slugs with no behaviour attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table tenants
            add column category text,
            add column trial_ends_at timestamptz,
            add constraint tenants_category_check check (category in (
                'hair_salon', 'barber', 'nails', 'beauty', 'massage', 'physiotherapy', 'other'
            )),
            drop constraint tenants_subscription_state_check,
            add constraint tenants_subscription_state_check check (subscription_state in (
                'active', 'grace', 'past_due', 'canceled', 'incomplete', 'trial'
            ))");
    }

    public function down(): void
    {
        DB::statement("alter table tenants
            drop constraint tenants_subscription_state_check,
            add constraint tenants_subscription_state_check check (subscription_state in (
                'active', 'grace', 'past_due', 'canceled', 'incomplete'
            )),
            drop constraint tenants_category_check,
            drop column trial_ends_at,
            drop column category");
    }
};
