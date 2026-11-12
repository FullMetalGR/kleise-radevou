<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * The two lines the payment layer writes for whoever runs the platform and shows no person: a
 * connection test that threw against its contract, and a platform connection opening a payment
 * while no platform fee is configured.
 *
 * Nothing handed here carries a credential: a thrown check's message arrives struck of every
 * value the connection declares, and the fee's line names the condition alone. Its answerer
 * never throws, since neither line may fail the test it reports on or a tenant's checkout.
 */
interface PaymentLog
{
    /**
     * A connection test threw where its contract says it answers: the provider tested, the
     * class thrown, and what it said struck of every credential value the test was lent.
     */
    public function checkThrew(string $provider, string $class, string $message): void;

    /**
     * A platform connection is opening a payment while no platform fee is configured, so the
     * payment carries none: a deployment's mistake, since the fee is set at zero at launch.
     */
    public function feeUnset(): void;
}
