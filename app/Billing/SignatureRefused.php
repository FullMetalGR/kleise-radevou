<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * An inbound notification the billing provider's signature does not vouch for, or one it vouches
 * for whose body is not an event the port can read (not JSON, or no id, type or object). Nothing
 * in the body is read before the signature holds.
 */
final class SignatureRefused extends RuntimeException {}
