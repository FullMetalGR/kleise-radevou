<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenancy\SchemaSwitch;
use App\Tenancy\TenantMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A built schema taken back to the moment before one tenant migration ran, so tenant:migrate meets
 * it as it meets a live schema built earlier: the migration's own down() inside the schema, as the
 * command runs up(), and the schema's ran list without it. The down() runs in a transaction of its
 * own, a savepoint under a wrapping one, so a down() that refuses leaves the schema as it was and
 * aborts nothing around it.
 */
final class TenantMigration
{
    /** Migration 000026, the fifth actor shape on audit_log. */
    public const string SUPPORT_ACTOR = '0001_01_01_000026_add_support_actor_to_audit_log';

    /** Migration 000027, the payment link's and the horizon's bounds and the implicit link. */
    public const string BOUNDS_AND_LINK = '0001_01_01_000027_bound_payment_ttl_and_horizon_and_link_the_implicit_member';

    /** Migration 000028, the customer columns nullable together, an erased customer. */
    public const string ERASABLE_CUSTOMER = '0001_01_01_000028_allow_an_erased_customer_on_bookings';

    /** Migration 000029, the tenant's chosen language and the booking's. */
    public const string LANGUAGES = '0001_01_01_000029_add_language_to_settings_and_bookings';

    /** Migration 000030, the shift patterns and overrides and the copy of the weekday rules. */
    public const string SHIFTS = '0001_01_01_000030_create_shift_patterns_and_shift_overrides';

    /** Migration 000031, how every calendar grid colours its blocks. */
    public const string COLOUR_BY = '0001_01_01_000031_add_colour_by_to_settings';

    /** Migration 000032, the weekday rules dropped once the patterns are every reader's. */
    public const string RULES_DROPPED = '0001_01_01_000032_drop_availability_rules';

    /** Migration 000033, the tenant's named theme, each saved accent mapped to its nearest. */
    public const string THEME = '0001_01_01_000033_add_theme_to_settings';

    /** Migration 000034, a member's own calendar colour, null for the automatic spread. */
    public const string STAFF_COLOUR = '0001_01_01_000034_add_colour_to_staff';

    /** Migration 000035, a customer's profile and notes, keyed by the address. */
    public const string CUSTOMER_PROFILES = '0001_01_01_000035_create_customer_profiles_and_notes';

    /** Migration 000040, the accent column dropped once the theme is the one colour a tenant chooses. */
    public const string ACCENT_DROPPED = '0001_01_01_000040_drop_brand_accent_from_settings';

    /** Migration 000041, the trail's index by created_at alone, for the latest rows of the whole trail. */
    public const string TRAIL_BY_TIME = '0001_01_01_000041_add_created_at_index_to_audit_log';

    public static function takeBack(string $schema, string $name): void
    {
        $migration = require TenantMigrator::path().'/'.$name.'.php';

        if (! $migration instanceof Migration || ! method_exists($migration, 'down')) {
            throw new LogicException(sprintf('The migration %s has no down() to take a schema back through.', $name));
        }

        DB::transaction(function () use ($schema, $name, $migration): void {
            SchemaSwitch::within(DB::connection(), $schema, fn () => $migration->down());
            DB::table($schema.'.migrations')->where('migration', $name)->delete();
        });
    }
}
