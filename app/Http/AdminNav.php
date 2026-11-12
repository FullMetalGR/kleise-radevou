<?php

declare(strict_types=1);

namespace App\Http;

use App\Billing\Entitlements;
use App\Billing\Feature;
use App\Tenancy\Permission;
use Illuminate\Contracts\Translation\Translator;

/**
 * The rail, in one place on the server.
 *
 * Each entry names its label, its URL, its group, the route it belongs to, the permissions that
 * show it, any one of them admitting as the route's own declaration does, and its feature gate, if
 * any. The rail a principal receives is this table filtered by their permissions, and the tenant's
 * entitlements, and the client renders it verbatim with no second gate: a gate in two places
 * disagrees with itself eventually, and the copy here is the one the route's own declaration
 * agrees with.
 *
 * A gate is of one of two kinds: with(feature) shows the entry while the tenant has the feature,
 * without(feature) while it lacks it. The Staff screen is one place in the rail under two labels
 * by these two kinds: Staff with the staff feature, Closures without it, the same permission, URL
 * and route, so no client code reads a feature to choose the label.
 *
 * Each screen's entry is in entries(), in rail order, naming the same permission its route
 * declares. An entry is current when the request's route is the entry's route or one named under
 * it (admin.bookings and admin.bookings.show both light Bookings), so a page that moves moves here
 * and in its route and nowhere else.
 *
 * The pending count belongs to Bookings alone and arrives with it.
 *
 * Every label and group is a key of lang/{en,el}/admin/shell.php's nav, read through the
 * translator in the request's language, the user's, when the rail is built; the client groups the
 * entries by the group's words as they arrive.
 */
final readonly class AdminNav
{
    public const string SCHEDULE = 'admin/shell.nav.groups.schedule';

    public const string BUSINESS = 'admin/shell.nav.groups.business';

    /** The route of the one entry that carries a pending count. */
    public const string BOOKINGS = 'admin.bookings';

    /** The Customers screen's entry. */
    public const string CUSTOMERS_LABEL = 'admin/shell.nav.customers';

    /** The Reports screen's entry. */
    public const string REPORTS_LABEL = 'admin/shell.nav.reports';

    /**
     * The request attribute of a page no entry stands for, the 404 page inside the shell: set,
     * the rail lights no entry whatever route answered.
     */
    public const string NO_ENTRY = 'admin.nav.no-entry';

    /**
     * @return list<array{label: string, url: string, group: string, route: string, permission: list<Permission>, gate: array{feature: Feature, present: bool}|null}>
     */
    private static function entries(): array
    {
        return [
            // Home, the landing of every viewBookings holder.
            ['label' => 'admin/shell.nav.home', 'url' => AdminSession::PREFIX, 'group' => self::SCHEDULE, 'route' => 'admin.home', 'permission' => [Permission::ViewBookings], 'gate' => null],
            // One Calendar for every role, a viewer holding viewOwnCalendar alone pinned there to
            // their own row, and their landing.
            ['label' => 'admin/shell.nav.calendar', 'url' => AdminSession::PREFIX.'/calendar', 'group' => self::SCHEDULE, 'route' => 'admin.calendar', 'permission' => [Permission::ViewBookings, Permission::ViewOwnCalendar], 'gate' => null],
            ['label' => 'admin/shell.nav.bookings', 'url' => AdminSession::PREFIX.'/bookings', 'group' => self::SCHEDULE, 'route' => self::BOOKINGS, 'permission' => [Permission::ViewBookings], 'gate' => null],
            // Who works when, week by week, with the staff feature alone: a floor's hours are
            // Settings' Opening hours.
            ['label' => 'admin/shell.nav.shifts', 'url' => AdminSession::PREFIX.'/shifts', 'group' => self::SCHEDULE, 'route' => 'admin.shifts', 'permission' => [Permission::ManageSettings], 'gate' => self::with(Feature::Staff)],
            ['label' => 'admin/shell.nav.services', 'url' => AdminSession::PREFIX.'/services', 'group' => self::BUSINESS, 'route' => 'admin.services', 'permission' => [Permission::ViewBookings], 'gate' => null],
            ['label' => 'admin/shell.nav.staff', 'url' => AdminSession::PREFIX.'/staff', 'group' => self::BUSINESS, 'route' => 'admin.staff', 'permission' => [Permission::ViewBookings], 'gate' => self::with(Feature::Staff)],
            // The same screen without the feature, labelled by what the tenant can still change
            // there: a floor's screen is headed Closures too, a roster with other rows keeps Staff.
            ['label' => 'admin/shell.nav.closures', 'url' => AdminSession::PREFIX.'/staff', 'group' => self::BUSINESS, 'route' => 'admin.staff', 'permission' => [Permission::ViewBookings], 'gate' => self::without(Feature::Staff)],
            // Every address the bookings know, read-only, under the permission the CONTACT rule
            // reads, since each row links its address.
            ['label' => self::CUSTOMERS_LABEL, 'url' => AdminSession::PREFIX.'/customers', 'group' => self::BUSINESS, 'route' => 'admin.customers', 'permission' => [Permission::ManageBookings], 'gate' => null],
            // The figures over a range, computed when asked.
            ['label' => self::REPORTS_LABEL, 'url' => AdminSession::PREFIX.'/reports', 'group' => self::BUSINESS, 'route' => 'admin.reports', 'permission' => [Permission::ViewBookings], 'gate' => null],
            // Who signs in and as what, the owner's alone.
            ['label' => 'admin/shell.nav.team', 'url' => AdminSession::PREFIX.'/team', 'group' => self::BUSINESS, 'route' => 'admin.team', 'permission' => [Permission::ManageBilling], 'gate' => null],
            ['label' => 'admin/shell.nav.settings', 'url' => AdminSession::PREFIX.'/settings', 'group' => self::BUSINESS, 'route' => 'admin.settings', 'permission' => [Permission::ManageSettings], 'gate' => null],
            // Never filtered by the subscription state: this is the way back.
            ['label' => 'admin/shell.nav.billing', 'url' => AdminSession::PREFIX.'/billing', 'group' => self::BUSINESS, 'route' => 'admin.billing', 'permission' => [Permission::ManageBilling], 'gate' => null],
        ];
    }

    /**
     * The presence gate: the entry shows while the tenant has the feature.
     *
     * @return array{feature: Feature, present: bool}
     */
    private static function with(Feature $feature): array
    {
        return ['feature' => $feature, 'present' => true];
    }

    /**
     * The absence gate: the entry shows while the tenant lacks the feature.
     *
     * @return array{feature: Feature, present: bool}
     */
    private static function without(Feature $feature): array
    {
        return ['feature' => $feature, 'present' => false];
    }

    /**
     * The rail for one principal at one tenant, as the nav prop carries it, from the principal's
     * permissions: a user's role's, or support's, which lacks manageBilling and so the Billing
     * entry.
     *
     * The pending count rides on Bookings alone, and is null where there is nothing pending, so the
     * rail shows no badge reading zero. Each label and group is read through the translator given,
     * in its locale, the request's.
     *
     * @param  list<Permission>  $permissions
     * @param  int  $pending  the requests the owner can still decide, BookingRecords::pendingCount
     * @return list<array{label: string, url: string, group: string, pending: int|null, current: bool}>
     */
    public static function for(array $permissions, Entitlements $entitlements, ?string $route, int $pending, Translator $translator): array
    {
        $rail = [];

        foreach (self::visible($permissions, $entitlements) as $entry) {
            $rail[] = [
                'label' => self::words($translator, $entry['label']),
                'url' => $entry['url'],
                'group' => self::words($translator, $entry['group']),
                'pending' => $entry['route'] === self::BOOKINGS && $pending > 0 ? $pending : null,
                'current' => $route !== null && ($route === $entry['route'] || str_starts_with($route, $entry['route'].'.')),
            ];
        }

        return $rail;
    }

    /**
     * A key's words in the translator's locale; a key the file lacks reads as the key.
     */
    private static function words(Translator $translator, string $key): string
    {
        $words = $translator->get($key);

        return is_string($words) ? $words : $key;
    }

    /**
     * Where a principal lands after signing in: the first entry of their rail. Home is first for
     * a viewBookings holder, the owner, the manager and support; the Calendar admits
     * viewBookings or viewOwnCalendar, and every role and support holds one of them, so every
     * rail has it and the fallback is never reached; the staff role's first visible entry is the
     * Calendar, pinned to their own row.
     *
     * @param  list<Permission>  $permissions
     */
    public static function home(array $permissions, Entitlements $entitlements): string
    {
        return self::visible($permissions, $entitlements)[0]['url'] ?? AdminSession::PREFIX;
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<array{label: string, url: string, group: string, route: string, permission: list<Permission>, gate: array{feature: Feature, present: bool}|null}>
     */
    private static function visible(array $permissions, Entitlements $entitlements): array
    {
        return array_values(array_filter(
            self::entries(),
            fn (array $entry): bool => array_filter($entry['permission'], fn (Permission $held): bool => in_array($held, $permissions, true)) !== []
                && ($entry['gate'] === null || $entitlements->allows($entry['gate']['feature']) === $entry['gate']['present']),
        ));
    }
}
