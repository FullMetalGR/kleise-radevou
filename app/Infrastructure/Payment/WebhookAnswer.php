<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

/**
 * The payment webhook's two refusals, each answered with the envelope.
 */
enum WebhookAnswer
{
    /** The signature did not verify with the connection's secret: the envelope's 400. */
    case Refused;

    /** No provider or no connection by that name: the envelope's 404. */
    case Unknown;
}
