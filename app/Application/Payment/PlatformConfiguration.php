<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * Where an adapter reads platform mode's terms as they stand when it is asked, the platform's
 * keys and its fee, rather than as they stood when the adapter was built: one registry and one
 * adapter serve the whole application, and a fee changed in configuration applies to the next
 * payment object created.
 */
interface PlatformConfiguration
{
    public function read(): PlatformMode;
}
