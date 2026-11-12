<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * What a signed-in user may do. The slugs are what the client reads; it never reads the role.
 * Declared in the table's order, which is the order a role's list is answered in.
 */
enum Permission: string
{
    case ViewBookings = 'viewBookings';
    case ViewOwnCalendar = 'viewOwnCalendar';
    case ManageBookings = 'manageBookings';
    case ApproveBookings = 'approveBookings';
    case ManageSettings = 'manageSettings';
    case ManageBilling = 'manageBilling';
}
