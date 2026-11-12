<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * A sellable unit above the floor. Exactly one case at v1.
 *
 * There is no case for the floor. Services, bookings, the calendar and settings are what every
 * tenant has, and a resolver answering the empty set must leave all of them working: a thing that
 * must never be false should not be representable as false.
 */
enum Feature: string
{
    case Staff = 'staff';
}
