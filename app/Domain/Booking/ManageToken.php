<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Credential\Secret;

/**
 * The secret a customer's magic link carries to manage, cancel or reschedule a booking. The
 * plain value is generated once, hashed for the row, and given to nobody but the customer; the
 * row holds only the hash, and a comparison against the row is timing-safe. A Secret, the shape
 * it shares with My bookings' CustomerSecret: thirty-two random bytes, spelled in hex so it
 * travels in a URL unchanged.
 */
final readonly class ManageToken extends Secret {}
