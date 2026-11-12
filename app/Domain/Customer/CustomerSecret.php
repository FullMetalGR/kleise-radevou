<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Domain\Credential\Secret;

/**
 * A secret a customer holds for My bookings: the token a one-time link carries, or the secret the
 * session that link opens is named by. A Secret, the manage token's shape: thirty-two random bytes
 * spelled in hex, so a link carries it in a path segment unchanged; the row holds the sha256
 * alone, as the manage token's does, and a presented value is found by its hash.
 *
 * The plain value exists twice and briefly: in the message the link is mailed in, and in the
 * framework-encrypted cookie the session rides. Nothing else holds it, no log line, no page and
 * no row.
 */
final readonly class CustomerSecret extends Secret {}
