<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Three rules of the tenant schema the forms held and the rows did not:
 *
 * - settings.payment_ttl_hours is at least one hour. The settings form has refused anything less
 *   since the column was added, so no row breaks the CHECK and nothing is clamped.
 * - services.horizon_days runs from zero to 365 days. The form had no upper bound, so a row past
 *   365 is brought down to 365 first, and the one-sided CHECK is then replaced by the two-sided
 *   one under the same name in one statement, so the table is never without a bound.
 * - A service nobody performs, in a schema whose one staff row is the implicit member, is linked
 *   to that member. The create path left such a service with no performer, so the booking page
 *   listed it and offered no day; ServiceRecords::insert links a new one now, and this links the
 *   ones made before. A schema with a second staff row keeps every link as the owner ticked it.
 *   Only service_staff rows are written, and a link that already exists is left alone.
 *
 * Safe on a schema holding live bookings. The settings CHECK and the services lock take ACCESS
 * EXCLUSIVE on those two tables, held to the schema's commit, and the two CHECKs scan one row and
 * the catalogue; the services lock comes before the clamp so no write lands between the clamp and
 * the CHECK it serves. Nothing of bookings or booking_items is touched. Under ExclusiveStep's rule
 * the step waits at most its lock timeout for those locks and fails rather than stall the
 * schema's readers behind it, the schema rolling back whole to be migrated again. On a schema
 * being built there are no rows, no staff yet and no reader, and the three changes are a moment.
 *
 * The bounds are spelled here rather than read from a constant, for the reason migration 000010
 * gives: a shipped migration must not change when a constant does.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared(<<<'SQL'
                alter table settings
                    add constraint settings_payment_ttl_hours_check check (payment_ttl_hours >= 1);

                lock table services in access exclusive mode;

                update services set horizon_days = 365 where horizon_days > 365;

                alter table services
                    drop constraint services_horizon_days_check,
                    add constraint services_horizon_days_check check (horizon_days >= 0 and horizon_days <= 365);

                insert into service_staff (service_id, staff_id)
                select services.id, staff.id
                from services
                cross join staff
                where staff.is_implicit
                    and (select count(*) from staff) = 1
                    and not exists (select 1 from service_staff where service_staff.service_id = services.id)
                on conflict do nothing;
                SQL);
        });
    }

    /**
     * Back to the one-sided horizon CHECK and no CHECK on the payment link. The clamped horizons
     * and the links stay: a rollback that unlinked them would take those services off the booking
     * page again, and a horizon past 365 days was never a value anybody chose on purpose.
     */
    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared(<<<'SQL'
                alter table settings drop constraint settings_payment_ttl_hours_check;

                alter table services
                    drop constraint services_horizon_days_check,
                    add constraint services_horizon_days_check check (horizon_days >= 0);
                SQL);
        });
    }
};
